<?php

namespace Sv\Network\VmsRtbw;

/**
 * Enum for console color codes.
 *
 * Provides ANSI escape sequences for various colors that can be used
 * to format console output.
 */
enum Color: string
{
    // ANSI escape sequence prefix
    private const string ESCAPE = "\033[";

    // Color definitions
    case DEFAULT = self::ESCAPE . "39m";
    case BLACK = self::ESCAPE . "30m";
    case RED = self::ESCAPE . "31m";
    case GREEN = self::ESCAPE . "32m";
    case YELLOW = self::ESCAPE . "33m";
    case BLUE = self::ESCAPE . "34m";
    case MAGENTA = self::ESCAPE . "35m";
    case CYAN = self::ESCAPE . "36m";
    case LIGHT_GRAY = self::ESCAPE . "37m";
    case DARK_GRAY = self::ESCAPE . "90m";
    case LIGHT_RED = self::ESCAPE . "91m";
    case LIGHT_GREEN = self::ESCAPE . "92m";
    case LIGHT_YELLOW = self::ESCAPE . "93m";
    case LIGHT_BLUE = self::ESCAPE . "94m";
    case LIGHT_MAGENTA = self::ESCAPE . "95m";
    case LIGHT_CYAN = self::ESCAPE . "96m";
    case WHITE = self::ESCAPE . "97m";
}