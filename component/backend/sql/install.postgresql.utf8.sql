--
-- @package   mcp4j
-- @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
-- @license   GNU General Public License version 3, or later
--

CREATE TABLE IF NOT EXISTS "#__mcp4j_sessions" (
    "session_id"       CHAR(43) NOT NULL,
    "user_id"          BIGINT NOT NULL,
    "protocol_version" VARCHAR(16) NOT NULL DEFAULT '',
    "client_info"      TEXT NOT NULL DEFAULT '',
    "created"          TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    "last_used"        TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    "expires"          TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY ("session_id")
);

CREATE INDEX IF NOT EXISTS "idx_mcp4j_sessions_user_id" ON "#__mcp4j_sessions" ("user_id");
CREATE INDEX IF NOT EXISTS "idx_mcp4j_sessions_expires" ON "#__mcp4j_sessions" ("expires");
