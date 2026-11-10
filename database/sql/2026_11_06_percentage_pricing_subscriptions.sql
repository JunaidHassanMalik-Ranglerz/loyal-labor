-- Percentage-based subscription pricing
-- Run this on live (phpMyAdmin / MySQL) if artisan migrate is unavailable.
-- Safe to re-run: uses IF NOT EXISTS style checks via information_schema.

SET @db := DATABASE();

-- original_price
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'original_price'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `subscriptions` ADD COLUMN `original_price` DECIMAL(10,2) NULL AFTER `price_pkr`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- discount_percentage
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'discount_percentage'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `subscriptions` ADD COLUMN `discount_percentage` DECIMAL(5,2) NULL DEFAULT 0 AFTER `original_price`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- payable_price
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'payable_price'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `subscriptions` ADD COLUMN `payable_price` DECIMAL(10,2) NULL AFTER `discount_percentage`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill existing plans without changing what customers currently pay
UPDATE `subscriptions`
SET
  `original_price` = GREATEST(COALESCE(`price_pkr`, 0), 0),
  `payable_price` = CASE
    WHEN `payable_price` IS NOT NULL AND `payable_price` >= 0
      THEN LEAST(GREATEST(COALESCE(`price_pkr`, 0), 0), `payable_price`)
    WHEN `saving_price` IS NOT NULL AND `saving_price` > 0 AND `saving_price` <= GREATEST(COALESCE(`price_pkr`, 0), 0)
      THEN `saving_price`
    WHEN COALESCE(`discount_percent`, 0) > 0 AND COALESCE(`discount_percent`, 0) <= 100 AND COALESCE(`price_pkr`, 0) > 0
      THEN ROUND(GREATEST(COALESCE(`price_pkr`, 0), 0) - (GREATEST(COALESCE(`price_pkr`, 0), 0) * `discount_percent` / 100), 2)
    ELSE GREATEST(COALESCE(`price_pkr`, 0), 0)
  END;

UPDATE `subscriptions`
SET
  `discount_percentage` = CASE
    WHEN GREATEST(COALESCE(`original_price`, 0), 0) > 0
      THEN ROUND(((GREATEST(COALESCE(`original_price`, 0), 0) - GREATEST(COALESCE(`payable_price`, 0), 0)) / GREATEST(COALESCE(`original_price`, 0), 0)) * 100, 2)
    ELSE 0
  END,
  `price_pkr` = GREATEST(COALESCE(`original_price`, 0), 0),
  `saving_price` = GREATEST(COALESCE(`payable_price`, 0), 0),
  `discount_percent` = ROUND(CASE
    WHEN GREATEST(COALESCE(`original_price`, 0), 0) > 0
      THEN ((GREATEST(COALESCE(`original_price`, 0), 0) - GREATEST(COALESCE(`payable_price`, 0), 0)) / GREATEST(COALESCE(`original_price`, 0), 0)) * 100
    ELSE 0
  END);

-- Optional: mark migration as run if Laravel migrations table exists
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_06_120000_add_percentage_pricing_to_subscriptions_table', COALESCE((SELECT MAX(batch) FROM `migrations`), 0) + 1
FROM DUAL
WHERE EXISTS (
  SELECT 1 FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations'
)
AND NOT EXISTS (
  SELECT 1 FROM `migrations`
  WHERE `migration` = '2026_11_06_120000_add_percentage_pricing_to_subscriptions_table'
);
