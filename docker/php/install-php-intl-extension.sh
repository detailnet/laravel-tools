#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

printf '%s%s%s\n' $(tput setaf 6) 'Installing php intl extension...' $(tput sgr0)

printf 'Updating packages                          ... ' && apt-get update -y > /dev/null 2>&1                       && exit_status $?
printf 'Installing prerequisite packages           ... ' && apt-get install -y apt-utils libicu-dev > /dev/null 2>&1 && exit_status $?
printf 'Configuring intl extension                 ... ' && docker-php-ext-configure intl > /dev/null 2>&1           && exit_status $?
printf 'Enabling intl extension                    ... ' && docker-php-ext-install -j$(nproc) intl > /dev/null 2>&1  && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'PHP intl extension installed.' $(tput sgr0)
