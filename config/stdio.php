<?php

return [
    // The wake source name the server-side stdio pipe registers under in the event loop.
    'server_pipe_name' => 'mcp.stdio.server',

    // Bytes read from stdin per fread() call while draining a readable wake.
    'read_chunk_size' => 65536,
];
