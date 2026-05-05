SRCDIR ?= /opt/fpp/src
include $(SRCDIR)/makefiles/common/setup.mk
include $(SRCDIR)/makefiles/platform/*.mk

all: libfpp-showpilot-sync.$(SHLIB_EXT)
debug: all

OBJECTS_fpp_showpilot_sync_so += src/FPPShowPilotSync.o
LIBS_fpp_showpilot_sync_so += -L$(SRCDIR) -lfpp
CXXFLAGS_src/FPPShowPilotSync.o += -I$(SRCDIR)

%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libfpp-showpilot-sync.$(SHLIB_EXT): $(OBJECTS_fpp_showpilot_sync_so) $(SRCDIR)/libfpp.$(SHLIB_EXT)
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_fpp_showpilot_sync_so) $(LIBS_fpp_showpilot_sync_so) $(LDFLAGS) -o $@

clean:
	rm -f libfpp-showpilot-sync.$(SHLIB_EXT) $(OBJECTS_fpp_showpilot_sync_so)
