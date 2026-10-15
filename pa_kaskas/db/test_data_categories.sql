-- ============================================================
-- *** TEST DATA ONLY - NOT PRODUCTION DATA ***
-- PA-KASKAS - optional sample rows for testing the Categories module.
--
-- Import this ONLY into a test database, after the schema.
-- Every row is marked "[TEST]" in its description so it is easy to find
-- and remove. To remove all of it:
--
--   DELETE FROM categories WHERE description LIKE '[TEST]%' AND parent_id IS NOT NULL;
--   DELETE FROM categories WHERE description LIKE '[TEST]%';
-- ============================================================

SET NAMES utf8mb4;

SET @gadgets   = UUID();
SET @groceries = UUID();
SET @travel    = UUID();
SET @utilities = UUID();

INSERT INTO categories (id, parent_id, name, description, is_active) VALUES
    (@gadgets,   NULL, 'Gadgets',   '[TEST] Phones, laptops and accessories', 1),
    (@groceries, NULL, 'Groceries', '[TEST] Supermarket and food purchases', 1),
    (@travel,    NULL, 'Travel',    '[TEST] Flights, hotels and bookings', 1),
    (@utilities, NULL, 'Utilities', '[TEST] Bills such as electricity and internet', 0);

INSERT INTO categories (id, parent_id, name, description, is_active) VALUES
    (UUID(), @gadgets,   'Mobile Phones', '[TEST] Smartphones bought on installment', 1),
    (UUID(), @gadgets,   'Laptops',       '[TEST] Notebook computers', 1),
    (UUID(), @gadgets,   'Accessories',   '[TEST] Chargers, cases and earphones', 1),
    (UUID(), @groceries, 'Supermarket',   '[TEST] Weekly grocery runs', 1),
    (UUID(), @travel,    'Airfare',       '[TEST] Plane tickets', 1),
    (UUID(), @travel,    'Hotels',        '[TEST] Accommodation', 1);
