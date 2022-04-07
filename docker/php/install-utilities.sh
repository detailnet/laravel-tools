#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

printf '%s%s%s\n' $(tput setaf 6) 'Installing additional utilities...' $(tput sgr0)

printf 'Updating packages                          ... ' && apt-get update -y > /dev/null 2>&1             && exit_status $?
printf 'Installing SSH client                      ... ' && apt-get install -y ssh-client > /dev/null 2>&1 && exit_status $?
printf 'Installing GIT                             ... ' && apt-get install -y git > /dev/null 2>&1        && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'Additional utilities installed.' $(tput sgr0)

