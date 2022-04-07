#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

printf '%s%s%s\n' $(tput setaf 6) 'Installing php PDO DBlib extension...' $(tput sgr0)

printf 'Updating packages                          ... ' && apt-get update -y > /dev/null 2>&1                                                     && exit_status $?
printf 'Installing prerequisite packages           ... ' && apt-get install -y apt-utils freetds-dev > /dev/null 2>&1                              && exit_status $?
printf 'Configuring PDO DBlib extension            ... ' && docker-php-ext-configure pdo_dblib --with-libdir=lib/x86_64-linux-gnu > /dev/null 2>&1 && exit_status $?
printf 'Enabling PDO DBlib extension               ... ' && docker-php-ext-install -j$(nproc) pdo_dblib > /dev/null 2>&1                           && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'PHP PDO DBlib extension installed.' $(tput sgr0)
