<?php
/** Additive inventory schema, applied by VSR_Installer. @package VariationStockReport */
defined( 'ABSPATH' ) || exit;
return array(
"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			variation_id BIGINT UNSIGNED NOT NULL,
			stock_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL,
			change_type VARCHAR(32) NOT NULL,
			quantity DECIMAL(19,4) NOT NULL DEFAULT 0,
			stock_before DECIMAL(19,4) NULL,
			stock_after DECIMAL(19,4) NULL,
			order_id BIGINT UNSIGNED NULL,
			order_item_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			source VARCHAR(40) NOT NULL DEFAULT 'unknown',
			event_key VARCHAR(191) NULL,
			note VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY variation_created (variation_id, created_at),
			KEY owner_created (stock_owner_id, created_at),
			KEY owner_type_created (stock_owner_id, change_type, created_at),
			KEY product_id (product_id),
			KEY order_id (order_id),
			KEY order_item_id (order_item_id),
			KEY created_id (created_at, id),
			KEY type_created (change_type, created_at)
		) ENGINE=InnoDB {$charset_collate};",
"CREATE TABLE {$operations} (
			operation_key varchar(64) NOT NULL,
			payload_hash varchar(64) NOT NULL,
			result longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (operation_key),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset_collate};",
);
