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
 *
 * Cooldown enforcement (v0.14.0+):
 *
 * Also hooks FPP's "query_next" playlist callback, which FPP calls
 * synchronously the moment a playlist item finishes, before it advances.
 * If the next item in the running (top-level) playlist is a sequence that
 * ShowPilot has put in cooldown, we ask FPP to continue the same playlist
 * at the first item after it that is NOT in cooldown.
 *
 * This replaces the pre-0.14 approach of rewriting the operator's playlist
 * JSON on disk (removing the cooled entry, re-inserting it later). Nothing
 * the operator owns is ever modified: if this plugin is disabled, crashes,
 * or is uninstalled, FPP simply plays the playlist as written.
 *
 * The cooldown list is written by showpilot_listener.php to
 * <media>/config/showpilot-cooldown-active.json:
 *   { "cooldowns": { "SequenceName": <until epoch seconds>, ... },
 *     "exclude":   [ "<request pool playlist>", "ShowPilot Queue" ] }
 *
 * Why the jump runs on a worker thread instead of inside the callback:
 * the callback runs on fppd's playlist thread while it holds the playlist
 * mutex mid-transition. FPP 10 defers a re-entrant Play() safely, but
 * FPP 8.x/9.x would run it inline and clobber the transition's state.
 * A separate thread blocks on that same mutex until the transition has
 * fully completed, then performs FPP's own "jump to index in the already
 * running playlist" path (Playlist::Play with same file + same repeat +
 * position >= 0) — no reload, shuffle order and schedule entry preserved.
 * Same code path on every supported FPP version. The trade-off is that
 * FPP has already started the cooled item when the jump lands, so it is
 * cut after a few milliseconds rather than never starting at all.
 */

#include "fpp-pch.h"

#include <sys/stat.h>
#include <fcntl.h>
#include <pwd.h>
#include <unistd.h>
#include <atomic>
#include <cerrno>
#include <string>
#include <cstring>
#include <mutex>

#include <condition_variable>
#include <ctime>
#include <set>
#include <thread>

#include "Plugin.h"
#include "MultiSync.h"
#include "Player.h"

#define SHOWPILOT_FIFO_PATH "/tmp/SHOWPILOT_FIFO"
#define SHOWPILOT_COOLDOWN_FILE "/showpilot-cooldown-active.json"

// FPP_DIR_CONFIG resolves the real media dir on every current image; keep a
// literal fallback so the plugin still builds against any tree lacking it.
static std::string ShowPilotCooldownPath() {
#ifdef FPP_DIR_CONFIG
    return FPP_DIR_CONFIG(SHOWPILOT_COOLDOWN_FILE);
#else
    return std::string("/home/fpp/media/config") + SHOWPILOT_COOLDOWN_FILE;
#endif
}

class ShowPilotPlugin : public FPPPlugin, public MultiSyncPlugin
{
public:
    ShowPilotPlugin()
        : FPPPlugin("fpp-showpilot-sync"),
          m_stopWorker(false),
          m_hasJob(false)
    {
        LogInfo(VB_PLUGIN, "ShowPilot: Initializing MultiSync plugin\n");
        MultiSync::INSTANCE.addMultiSyncPlugin(this);
        {
            std::lock_guard<std::mutex> lock(m_mutex);
            openFifoLocked();
        }
        m_worker = std::thread(&ShowPilotPlugin::cooldownWorker, this);
        LogInfo(VB_PLUGIN, "ShowPilot: cooldown playlist hook active\n");
    }

    virtual ~ShowPilotPlugin()
    {
        {
            std::lock_guard<std::mutex> lk(m_jobMutex);
            m_stopWorker = true;
        }
        m_jobCv.notify_all();
        if (m_worker.joinable()) m_worker.join();
        MultiSync::INSTANCE.removeMultiSyncPlugin(this);
        std::lock_guard<std::mutex> lock(m_mutex);
        if (m_fd >= 0) { close(m_fd); m_fd = -1; }
    }

    // ---- Cooldown: FPP playlist decision hook -------------------------
    virtual void playlistCallback(const Json::Value &playlist, const std::string &action,
                                  const std::string &section, int item) override
    {
        if (action != "query_next") return;
        try {
            handleQueryNext(playlist, section, item);
        } catch (...) {
            // Never let a cooldown problem take down fppd's playlist thread.
            LogWarn(VB_PLUGIN, "ShowPilot: cooldown hook error (ignored)\n");
        }
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
        if (m_lastMediaHalfSecond.exchange(curTS) == curTS) return;
        char buf[32];
        snprintf(buf, sizeof(buf), "%.6f", (double)seconds);
        write("MediaSyncPacket/" + filename + "/" + std::string(buf) + "\n");
    }

private:
    int m_fd = -1;                             // guarded by m_mutex
    std::atomic<int> m_lastMediaHalfSecond{-1};
    std::mutex m_mutex;
    bool m_warnedNotFifo = false;              // guarded by m_mutex

    struct JumpJob {
        std::string playlist;
        int repeat = 0;
        int expectedPos = 0;   // 1-based global position of the cooled item (Player::GetPosition())
        int targetPos = 0;     // 0-based global position to continue at (Playlist::Play position)
        std::string cooledName;
        std::string targetName;
    };
    std::thread m_worker;
    std::mutex m_jobMutex;
    std::condition_variable m_jobCv;
    std::atomic<bool> m_stopWorker;
    bool m_hasJob;
    JumpJob m_job;

    // Entry name as ShowPilot knows it: file name without directory or extension.
    static std::string entryName(const Json::Value &e)
    {
        std::string type = e.get("type", "").asString();
        std::string f;
        if (type == "sequence" || type == "both") f = e.get("sequenceName", "").asString();
        else if (type == "media") f = e.get("mediaName", "").asString();
        else return "";
        size_t slash = f.find_last_of('/');
        if (slash != std::string::npos) f = f.substr(slash + 1);
        size_t dot = f.find_last_of('.');
        if (dot != std::string::npos && dot > 0) f = f.substr(0, dot);
        return f;
    }

    // Loads active (unexpired) cooldowns. Returns false if nothing to enforce
    // for this playlist.
    static bool loadCooldowns(const std::string &playlistName, std::set<std::string> &out)
    {
        std::string path = ShowPilotCooldownPath();
        if (access(path.c_str(), R_OK) != 0) return false;
        Json::Value root;
        if (!LoadJsonFromFile(path, root) || !root.isObject()) return false;

        const Json::Value &ex = root["exclude"];
        if (ex.isArray()) {
            for (const auto &x : ex) {
                if (x.isString() && x.asString() == playlistName) return false;
            }
        }
        const Json::Value &cd = root["cooldowns"];
        if (!cd.isObject()) return false;
        time_t now = time(nullptr);
        for (const auto &name : cd.getMemberNames()) {
            const Json::Value &v = cd[name];
            if (v.isNumeric() && (time_t)v.asInt64() > now) out.insert(name);
        }
        return !out.empty();
    }

    void handleQueryNext(const Json::Value &playlist, const std::string &section, int item)
    {
        std::string name = playlist.get("name", "").asString();
        if (name.empty()) return;

        // Only the top-level playlist. Inserted playlists (ShowPilot's own
        // request queue, anything else inserted) report their own name here.
        if (name != Player::INSTANCE.GetPlaylistName()) return;

        std::set<std::string> cooled;
        if (!loadCooldowns(name, cooled)) return;

        // We're on the playlist thread holding its (recursive) mutex, so this
        // reflects the live, in-memory order — including a shuffled order.
        Json::Value cfg = Player::INSTANCE.GetConfig();
        if (cfg.get("name", "").asString() != name) return;

        const Json::Value &main = cfg["mainPlaylist"];
        if (!main.isArray() || main.empty()) return;
        int leadIn = cfg["leadIn"].isArray() ? (int)cfg["leadIn"].size() : 0;
        int mainSize = (int)main.size();

        int nextIdx;
        if (section == "MainPlaylist") {
            nextIdx = item + 1;
        } else if (section == "LeadIn") {
            if (item + 1 < leadIn) return;   // next is still lead-in
            nextIdx = 0;
        } else {
            return;                          // lead-out: never touched
        }
        // End of main playlist: FPP handles loop/lead-out itself; jumping
        // across the loop boundary would bypass its loop accounting.
        if (nextIdx < 0 || nextIdx >= mainSize) return;

        std::string nextName = entryName(main[nextIdx]);
        if (nextName.empty() || !cooled.count(nextName)) return;

        int j = nextIdx;
        while (j < mainSize) {
            std::string n = entryName(main[j]);
            if (n.empty() || !cooled.count(n)) break;
            j++;
        }
        if (j >= mainSize) {
            LogInfo(VB_PLUGIN, "ShowPilot: '%s' is in cooldown but every remaining item is too; letting it play\n",
                    nextName.c_str());
            return;
        }

        JumpJob job;
        job.playlist = name;
        job.repeat = playlist.get("repeat", 0).asInt();
        job.expectedPos = leadIn + nextIdx + 1;
        job.targetPos = leadIn + j;
        job.cooledName = nextName;
        job.targetName = entryName(main[j]);
        {
            std::lock_guard<std::mutex> lk(m_jobMutex);
            m_job = job;
            m_hasJob = true;
        }
        m_jobCv.notify_one();
        LogInfo(VB_PLUGIN, "ShowPilot: next item '%s' is in cooldown; will continue at '%s'\n",
                job.cooledName.c_str(), job.targetName.c_str());
    }

    void cooldownWorker()
    {
        while (true) {
            JumpJob job;
            {
                std::unique_lock<std::mutex> lk(m_jobMutex);
                m_jobCv.wait(lk, [this] { return m_stopWorker || m_hasJob; });
                if (m_stopWorker) return;
                job = m_job;
                m_hasJob = false;
            }
            try {
                runJump(job);
            } catch (...) {
                LogWarn(VB_PLUGIN, "ShowPilot: cooldown jump error (ignored)\n");
            }
        }
    }

    void runJump(const JumpJob &job)
    {
        // Player::GetInfo() takes the playlist mutex, so this blocks until the
        // transition that fired query_next has fully completed. Then confirm
        // FPP really is on the cooled item before touching anything — an
        // inserted request, a manual command, or a schedule change in between
        // means the situation changed and we leave it alone.
        for (int attempt = 0; attempt < 20; attempt++) {
            if (m_stopWorker) return;
            Json::Value info = Player::INSTANCE.GetInfo();
            if (info.get("name", "").asString() != job.playlist) return;
            if (Player::INSTANCE.GetPlaylistName() != job.playlist) return;
            int pos = Player::INSTANCE.GetPosition();
            if (pos == job.expectedPos) {
                Player::INSTANCE.StartPlaylist(job.playlist, job.repeat, job.targetPos);
                LogInfo(VB_PLUGIN, "ShowPilot: skipped cooled '%s', continuing at '%s'\n",
                        job.cooledName.c_str(), job.targetName.c_str());
                return;
            }
            if (pos > job.expectedPos) return;   // already moved past it
            std::this_thread::sleep_for(std::chrono::milliseconds(5));
        }
    }

    // fppd runs as root and /tmp is world-writable, so never follow a
    // symlink or reuse a non-FIFO someone else planted at this path.
    // Caller holds m_mutex.
    void openFifoLocked()
    {
        struct stat st;
        if (lstat(SHOWPILOT_FIFO_PATH, &st) != 0) {
            if (mkfifo(SHOWPILOT_FIFO_PATH, 0660) != 0 && errno != EEXIST) {
                LogWarn(VB_PLUGIN, "ShowPilot: mkfifo failed: %s\n", strerror(errno));
                return;
            }
            // The audio daemon runs as fpp, not root.
            if (struct passwd *pw = getpwnam("fpp")) {
                if (lchown(SHOWPILOT_FIFO_PATH, pw->pw_uid, pw->pw_gid) != 0) {
                    LogWarn(VB_PLUGIN, "ShowPilot: chown of FIFO failed: %s\n", strerror(errno));
                }
            }
        } else if (!S_ISFIFO(st.st_mode)) {
            if (!m_warnedNotFifo) {
                LogWarn(VB_PLUGIN, "ShowPilot: %s exists and is not a FIFO; ignoring it\n", SHOWPILOT_FIFO_PATH);
                m_warnedNotFifo = true;
            }
            return;
        }

        // Non-blocking: fails with ENXIO until the daemon has its end open,
        // and never blocks fppd if the daemon stops reading.
        m_fd = open(SHOWPILOT_FIFO_PATH, O_WRONLY | O_NONBLOCK | O_NOFOLLOW | O_CLOEXEC);
        if (m_fd < 0) {
            LogDebug(VB_PLUGIN, "ShowPilot: FIFO not ready (daemon not running): %s\n", strerror(errno));
            return;
        }
        if (fstat(m_fd, &st) != 0 || !S_ISFIFO(st.st_mode)) {
            close(m_fd);
            m_fd = -1;
            return;
        }
        fchmod(m_fd, 0660);
        LogInfo(VB_PLUGIN, "ShowPilot: FIFO opened: %s\n", SHOWPILOT_FIFO_PATH);
    }

    // Called from fppd's media threads; m_fd is shared, so serialize.
    void write(const std::string &message)
    {
        std::lock_guard<std::mutex> lock(m_mutex);
        if (m_fd < 0) {
            // The daemon may have started since the last attempt.
            openFifoLocked();
            if (m_fd < 0) return;
        }

        ssize_t ret = ::write(m_fd, message.c_str(), message.size());
        if (ret < 0 && (errno == EPIPE || errno == ENXIO)) {
            // Daemon closed its end — reopen on the next message.
            close(m_fd);
            m_fd = -1;
        }
        // EAGAIN means the pipe is full: drop the message rather than block.
    }
};

extern "C" {
    FPPPlugin *createPlugin() {
        return new ShowPilotPlugin();
    }
}
