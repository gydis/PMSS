# ~/.bashrc: executed by bash(1) for non-login shells.
# see /usr/share/doc/bash/examples/startup-files (in the package bash-doc)
# for examples

# If not running interactively, don't do anything
[ -z "$PS1" ] && return

# don't put duplicate lines in the history. See bash(1) for more options
# don't overwrite GNU Midnight Commander's setting of `ignorespace'.
export HISTCONTROL=$HISTCONTROL${HISTCONTROL+,}ignoredups
# ... or force ignoredups and ignorespace
export HISTCONTROL=ignoreboth

# append to the history file, don't overwrite it
shopt -s histappend

# for setting history length see HISTSIZE and HISTFILESIZE in bash(1)

# check the window size after each command and, if necessary,
# update the values of LINES and COLUMNS.
shopt -s checkwinsize

# Set locale
export LANG=en_US.UTF-8
export LANGUAGE=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# make less more friendly for non-text input files, see lesspipe(1)
#[ -x /usr/bin/lesspipe ] && eval "$(SHELL=/bin/sh lesspipe)"

# set variable identifying the chroot you work in (used in the prompt below)
if [ -z "$debian_chroot" ] && [ -r /etc/debian_chroot ]; then
    debian_chroot=$(cat /etc/debian_chroot)
fi

# set a fancy prompt (non-color, unless we know we "want" color)
case "$TERM" in
    xterm-color) color_prompt=yes;;
esac

# uncomment for a colored prompt, if the terminal has the capability; turned
# off by default to not distract the user: the focus in a terminal window
# should be on the output of commands, not on the prompt
#force_color_prompt=yes

if [ -n "$force_color_prompt" ]; then
    if [ -x /usr/bin/tput ] && tput setaf 1 >&/dev/null; then
	# We have color support; assume it's compliant with Ecma-48
	# (ISO/IEC-6429). (Lack of such support is extremely rare, and such
	# a case would tend to support setf rather than setaf.)
	color_prompt=yes
    else
	color_prompt=
    fi
fi

#if [ "$color_prompt" = yes ]; then
    PS1='${debian_chroot:+($debian_chroot)}\[\033[01;32m\]\u@\h\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '
#else
#    PS1='${debian_chroot:+($debian_chroot)}\u@\h:\w\$ '
#fi
unset color_prompt force_color_prompt



# If this is an xterm set the title to user@host:dir
case "$TERM" in
xterm*|rxvt*)
    PS1="\[\e]0;${debian_chroot:+($debian_chroot)}\u@\h: \w\a\]$PS1"
    ;;
*)
    ;;
esac

# Alias definitions.
# You may want to put all your additions into a separate file like
# ~/.bash_aliases, instead of adding them here directly.
# See /usr/share/doc/bash-doc/examples in the bash-doc package.

#if [ -f ~/.bash_aliases ]; then
#    . ~/.bash_aliases
#fi

# enable color support of ls and also add handy aliases
if [ -x /usr/bin/dircolors ]; then
    eval "`dircolors -b`"
    alias ls='ls --color=auto'
    #alias dir='dir --color=auto'
    #alias vdir='vdir --color=auto'

    #alias grep='grep --color=auto'
    #alias fgrep='fgrep --color=auto'
    #alias egrep='egrep --color=auto'
fi

# some more ls aliases
#alias ll='ls -l'
#alias la='ls -A'
#alias l='ls -CF'
alias ls='ls --color=auto'

# enable programmable completion features (you don't need to enable
# this, if it's already enabled in /etc/bash.bashrc and /etc/profile
# sources /etc/bash.bashrc).
if [ -f /etc/bash_completion ]; then
    . /etc/bash_completion
fi


alias arrinfo='echo "RADARR-URL = https://$(hostname)/public-$(whoami)/radarr/" && echo "SONARR-URL = https://$(hostname)/public-$(whoami)/sonarr/" && echo "PROWLARR-URL = https://$(hostname)/public-$(whoami)/prowlarr/" && echo "JELLYFIN-URL = https://$(hostname)/public-$(whoami)/jellyfin/web/index.html" && echo ""'
alias passwordChange='newPassword=$(< /dev/urandom tr -dc A-NP-Za-km-np-z2-9 | head -c${1:-10};echo;); echo -e "$newPassword\n$newPassword" | passwd $(whoami); htpasswd -b -m $( [ -f ~/.lighttpd/.htpasswd ] || echo -n "-c" ) ~/.lighttpd/.htpasswd $(whoami) $newPassword; echo "New password is: $newPassword"'

# Display rootless Docker usage instructions
docker-help() {
  cat <<'EOF'
Rootless Docker Usage
=====================

Docker runs in user mode on this system. Useful commands:

    systemctl --user start docker.service   # start daemon
    systemctl --user restart docker.service # restart daemon
    docker ps                               # list running containers
    docker images                           # list downloaded images
    docker pull IMAGE                       # fetch image
    docker run IMAGE                        # run container

The environment variable `DOCKER_HOST` points at your user daemon:

    export DOCKER_HOST=unix:///run/user/\$(id -u)/docker.sock

If you require docker-compose, download the latest binary to ~/bin
and make it executable.

See https://docs.docker.com/engine/security/rootless/ for limitations.

Wireguard container
-------------------
Install the linuxserver.io Wireguard container with:

    install-wireguard.sh [PORT]

The script lives in ~/bin and defaults to a random free port if you do not
specify one. After launch, fetch client configs with
`docker container exec wireguard /app/show-peer 1`.
EOF
}


export PATH=~/bin:$PATH

export DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock
