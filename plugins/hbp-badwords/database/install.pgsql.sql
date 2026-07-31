-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
CREATE TABLE IF NOT EXISTS badwords (
    id          SERIAL        PRIMARY KEY,
    word        VARCHAR(255)  NOT NULL UNIQUE,
    created_at  TIMESTAMP     NOT NULL
);
