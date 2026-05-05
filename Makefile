SRCDIR ?= /opt/fpp/src
include $(SRCDIR)/makefiles/common/setup.mk
include $(SRCDIR)/makefiles/platform/*.mk

all: libshowpilot.$(SHLIB_EXT)
debug: all

OBJECTS_showpilot_so += src/FPPShowPilotSync.o
LIBS_showpilot_so += -L$(SRCDIR) -lfpp
CXXFLAGS_src/FPPShowPilotSync.o += -I$(SRCDIR)

%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libshowpilot.$(SHLIB_EXT): $(OBJECTS_showpilot_so) $(SRCDIR)/libfpp.$(SHLIB_EXT)
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_showpilot_so) $(LIBS_showpilot_so) $(LDFLAGS) -o $@

clean:
	rm -f libshowpilot.$(SHLIB_EXT) $(OBJECTS_showpilot_so)
