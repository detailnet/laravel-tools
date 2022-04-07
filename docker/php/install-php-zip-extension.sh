#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

#Ref: https://stackoverflow.com/questions/48700453/docker-image-build-with-php-zip-extension-shows-bundled-libzip-is-deprecated-w
printf '%s%s%s\n' $(tput setaf 6) 'Installing php zip extension...' $(tput sgr0)

printf 'Updating packages                          ... ' && apt-get update -y > /dev/null 2>&1                     && exit_status $?
printf 'Installing prerequisite packages           ... ' && apt-get install -y libzip-dev zip > /dev/null 2>&1     && exit_status $?
printf 'Configuring zip extension                  ... ' && docker-php-ext-configure zip > /dev/null 2>&1          && exit_status $?
printf 'Enabling zip extension                     ... ' && docker-php-ext-install -j$(nproc) zip > /dev/null 2>&1 && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'PHP zip extension installed.' $(tput sgr0)

