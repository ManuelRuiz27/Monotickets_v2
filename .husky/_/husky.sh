#!/bin/sh
if [ -z "$husky_skip_init" ]; then
  husky_skip_init=1
  export husky_skip_init
  if [ -f ~/.huskyrc ]; then
    . ~/.huskyrc
  fi
  sh -e "$0" "$@"
  exit $?
fi
