#!/bin/bash

export TERM="${TERM:=xterm}"

exit_status () {
    if [ $1 -ne 0 ]; then
        printf '%s%s%s\n' $(tput setaf 1) 'error' $(tput sgr0)
    else
        printf '%s%s%s\n' $(tput setaf 2) 'done' $(tput sgr0)
   fi
}

download_composer_installer() {
    EXPECTED_SIGNATURE=$(curl -s https://composer.github.io/installer.sig)
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_SIGNATURE=$(php -r "echo hash_file('SHA384', 'composer-setup.php');")

    if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
        >&2 echo 'ERROR: Invalid composer installer signature'
        rm composer-setup.php
        exit 1
    fi
}

install_composer() {
    php composer-setup.php --quiet
    RESULT=$?

    rm composer-setup.php

    if [ $RESULT -ne 0 ]
    then
        >&2 echo 'ERROR: composer installer encountered an error'
        exit 1
    fi

    mv composer.phar /usr/local/bin/composer
}

printf '%s%s%s\n' $(tput setaf 6) 'Installing composer...' $(tput sgr0)

printf 'Updating packages                          ... ' && apt-get update -y > /dev/null 2>&1            && exit_status $?
printf 'Installing prerequisite packages           ... ' && apt-get install -y curl > /dev/null 2>&1      && exit_status $?
printf 'Downloading composer installer             ... ' && download_composer_installer > /dev/null 2>&1  && exit_status $?
printf 'Installing composer                        ... ' && install_composer > /dev/null 2>&1             && exit_status $?

printf '%s%s%s\n' $(tput setaf 6) 'Composer installed.' $(tput sgr0)

