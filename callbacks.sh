#!/bin/bash

for var in "$@"
do
    case $var in
        -l|--list)
            echo "c++";
            exit 0;
        ;;
        -h|--help)
            exit 0
        ;;
        -v|--version)
            exit 0
        ;;
        --)
            break
        ;;
        *)
            printf "Unknown option %s\n" "$var"
            exit 1
        ;;
    esac
done
