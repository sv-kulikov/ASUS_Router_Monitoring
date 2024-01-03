<?php

namespace Sv\Network\VmsRtbw;

enum Color: string
{
    case DEFAULT = "\033[39m";
    case BLACK = "\033[30m";
    case RED = "\033[31m";
    case GREEN = "\033[32m";
    case YELLOW = "\033[33m";
    case BLUE = "\033[34m";
    case MAGENTA = "\033[35m";
    case CYAN = "\033[36m";
    case LIGHT_GRAY = "\033[37m";
    case DARK_GRAY = "\033[90m";
    case LIGHT_RED = "\033[91m";
    case LIGHT_GREEN = "\033[92m";
    case LIGHT_YELLOW = "\033[93m";
    case LIGHT_BLUE = "\033[94m";
    case LIGHT_MAGENTA = "\033[95m";
    case LIGHT_CYAN = "\033[96m";
    case WHITE = "\033[97m";
}