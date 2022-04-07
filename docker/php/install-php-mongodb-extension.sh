#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

printf '%s%s%s\n' $(tput setaf 6) 'Installing php MongoDB extension...' $(tput sgr0)

printf 'Updating packages                          ... ' && apt-get update -y > /dev/null 2>&1                        && exit_status $?
printf 'Installing prerequisite packages           ... ' && apt-get install -y pkg-config libssl-dev > /dev/null 2>&1 && exit_status $?
printf 'Updating PECL channels                     ... ' && pecl channel-update pecl.php.net > /dev/null 2>&1         && exit_status $?
printf 'Compiling mongodb extension (takes longer) ... ' && pecl install mongodb > /dev/null 2>&1                     && exit_status $?
printf 'Enabling mongodb extension                 ... ' && docker-php-ext-enable mongodb 2>&1                        && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'PHP MongoDB extension installed.' $(tput sgr0)
