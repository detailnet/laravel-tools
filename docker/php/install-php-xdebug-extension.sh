#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

printf '%s%s%s\n' $(tput setaf 6) 'Installing php xdebug extension...' $(tput sgr0)

printf 'Installing xdebug via pecl                 ... ' && pecl install xdebug > /dev/null 2>&1           && exit_status $?
printf 'Enabling xdebug extension                  ... ' && docker-php-ext-enable xdebug > /dev/null 2>&1  && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'PHP xdebug extension installed.' $(tput sgr0)

