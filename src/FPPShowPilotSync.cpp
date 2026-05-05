/**
 * ShowPilot FPP MultiSync Plugin
 * 
 * Hooks into FPP's MultiSync system to receive precise playback position
 * callbacks directly from FPP's internal engine. Writes sync events to
 * a named FIFO pipe at /tmp/SHOWPILOT_FIFO which the Node daemon reads.
 * 
 * This gives the daemon sub-millisecond accurate position data compared
 * to polling /api/fppd/status over HTTP every 250ms.
 * 
 * Events written (one per line):
 *   MediaSyncStart/filename
 *   MediaSyncStop/filename  
 *   MediaSyncPacket/filename/seconds
 *   MediaOpen/filename
 */

#include "fpp-pch.h"

#include <sys/stat.h>
#include <fcntl.h>
#include <unistd.h>
#include <string>
#include <cstring>
#include <mutex>

#include "Plugin.h"
#include "MultiSync.h"

#define SHOWPILOT_FIFO_PATH "/tmp/SHOWPILOT_FIFO"

class ShowPilotPlugin : public FPPPlugin, public MultiSyncPlugin
{
public:
    ShowPilotPlugin()
        : FPPPlugin("fpp-showpilot-sync"),
          m_fd(-1),
          m_lastMediaHalfSecond(-1)
    {
        LogInfo(VB_PLUGIN, "ShowPilot: Initializing MultiSync plugin\n");
        MultiSync::INSTANCE.addMultiSyncPlugin(this);
        initFifo();
    }

    virtual ~ShowPilotPlugin()
    {
        MultiSync::INSTANCE.removeMultiSyncPlugin(this);
        if (m_fd >= 0) { close(m_fd); m_fd = -1; }
    }

    virtual void SendMediaOpenPacket(const std::string &filename) override
    {
        write("MediaOpen/" + filename + "\n");
    }

    virtual void SendMediaSyncStartPacket(const std::string &filename) override
    {
        m_lastMediaHalfSecond = -1;
        write("MediaSyncStart/" + filename + "\n");
        LogInfo(VB_PLUGIN, "ShowPilot: MediaSyncStart: %s\n", filename.c_str());
    }

    virtual void SendMediaSyncStopPacket(const std::string &filename) override
    {
        m_lastMediaHalfSecond = -1;
        write("MediaSyncStop/" + filename + "\n");
        LogInfo(VB_PLUGIN, "ShowPilot: MediaSyncStop: %s\n", filename.c_str());
    }

    virtual void SendMediaSyncPacket(const std::string &filename, float seconds) override
    {
        // Only send when half-second boundary changes — ~2 updates/sec is enough
        int curTS = static_cast<int>(seconds * 2.0f);
        {
            std::lock_guard<std::mutex> lock(m_mutex);
            if (m_lastMediaHalfSecond == curTS) return;
            m_lastMediaHalfSecond = curTS;
        }
        char buf[32];
        snprintf(buf, sizeof(buf), "%.6f", (double)seconds);
        write("MediaSyncPacket/" + filename + "/" + std::string(buf) + "\n");
    }

private:
    int m_fd;
    int m_lastMediaHalfSecond;
    std::mutex m_mutex;

    void initFifo()
    {
        // Create FIFO if it doesn't exist
        struct stat st;
        if (stat(SHOWPILOT_FIFO_PATH, &st) != 0) {
            if (mkfifo(SHOWPILOT_FIFO_PATH, 0666) != 0) {
                LogWarn(VB_PLUGIN, "ShowPilot: mkfifo failed: %s\n", strerror(errno));
            }
        }
        chmod(SHOWPILOT_FIFO_PATH, 0666);

        // Open non-blocking so we don't block if daemon isn't reading
        m_fd = open(SHOWPILOT_FIFO_PATH, O_WRONLY | O_NONBLOCK);
        if (m_fd < 0) {
            LogInfo(VB_PLUGIN, "ShowPilot: FIFO not ready (daemon not running): %s\n", strerror(errno));
        } else {
            LogInfo(VB_PLUGIN, "ShowPilot: FIFO opened: %s\n", SHOWPILOT_FIFO_PATH);
        }
    }

    void write(const std::string &message)
    {
        if (m_fd < 0) {
            // Try to reopen — daemon may have started since we last tried
            m_fd = open(SHOWPILOT_FIFO_PATH, O_WRONLY | O_NONBLOCK);
            if (m_fd < 0) return;
            LogInfo(VB_PLUGIN, "ShowPilot: FIFO reconnected\n");
        }

        ssize_t ret = ::write(m_fd, message.c_str(), message.size());
        if (ret < 0) {
            if (errno == EPIPE || errno == ENXIO) {
                // Daemon closed its end — close and retry next time
                close(m_fd);
                m_fd = -1;
            }
            // EAGAIN = pipe full, drop the message (non-blocking)
        }
    }
};

extern "C" {
    FPPPlugin *createPlugin() {
        return new ShowPilotPlugin();
    }
}
