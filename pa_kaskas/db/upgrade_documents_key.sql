-- PA-KASKAS: fix "Field 'DB_ROW_HASH_1' doesn't have a default value" when saving a file.
-- documents.storage_key was UNIQUE on a TEXT column; MariaDB keeps that with a hidden
-- hash column that older versions (e.g. 10.4 in XAMPP) break after the table is altered.
-- The same rule as a normal unique index on the first 255 characters. No data is changed.
-- The app runs this by itself before the next upload; this file is for running it by hand.
-- Check first (both must return 0):
--   SELECT COUNT(*) FROM documents WHERE CHAR_LENGTH(storage_key) > 255;
--   SELECT COUNT(*) FROM (SELECT LEFT(storage_key, 255) k FROM documents GROUP BY k HAVING COUNT(*) > 1) x;
-- If the index has another name, see: SHOW INDEX FROM documents WHERE Column_name = 'storage_key';
ALTER TABLE documents
    DROP INDEX IF EXISTS storage_key,
    ADD UNIQUE KEY IF NOT EXISTS uq_documents_storage_key (storage_key(255));
