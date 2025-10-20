<?php

namespace Xvq\PhpAdb\Enum;

enum Network: string
{
    case TCP = 'tcp';
    case UNIX = 'unix';
    case LOCAL_ABSTRACT = 'localabstract';
    case LOCAL_FILESYSTEM = 'localfilesystem';
    case LOCAL = 'local';
    case DEV = 'dev';
    case LOCAL_RESERVED = 'localreserved';
}