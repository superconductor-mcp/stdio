<?php

namespace SuperconductorMCP\STDIO\Events;

/**
 * Posted once the server closes. Mail is delivered in order, so what the server sent while closing arrives first.
 */
readonly class ServerClosed {}
