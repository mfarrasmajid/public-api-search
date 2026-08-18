-- Runs once, on first creation of the data volume.

-- pgvector is pre-installed in the pgvector/pgvector image. Enabling the
-- extension now costs nothing and means phase 5 (semantic search) only needs
-- a migration to add the embedding column.
CREATE EXTENSION IF NOT EXISTS vector;

-- Trigram index support for the PostgreSQL fallback search driver.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
