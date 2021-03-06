#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

printf '%s%s%s\n' $(tput setaf 6) 'Installing php pcntl extension...' $(tput sgr0)

printf 'Enabling pcntl extension                    ... ' && docker-php-ext-install -j$(nproc) pcntl > /dev/null 2>&1  && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'PHP pcntl extension installed.' $(tput sgr0)
