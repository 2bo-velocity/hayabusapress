-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
CREATE TABLE IF NOT EXISTS badwords (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    word        VARCHAR(255)  NOT NULL UNIQUE,
    created_at  DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
