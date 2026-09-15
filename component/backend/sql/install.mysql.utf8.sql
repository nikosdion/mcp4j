--
-- @package   mcp4j
-- @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
-- @license   GNU General Public License version 3, or later
--

CREATE TABLE IF NOT EXISTS `#__mcp4j_sessions` (
    `session_id`       CHAR(43) NOT NULL,
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `protocol_version` VARCHAR(16) NOT NULL DEFAULT '',
    `client_info`      MEDIUMTEXT NOT NULL,
    `session_data`     LONGTEXT NOT NULL,
    `created`          DATETIME NOT NULL,
    `last_active`      DATETIME NOT NULL,
    `expires`          DATETIME NOT NULL,
    PRIMARY KEY (`session_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
