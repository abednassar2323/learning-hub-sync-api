<?php
declare(strict_types=1);

function setup_cloud(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cloud_rows (
          table_name VARCHAR(128) NOT NULL,
          pk_value   VARCHAR(64)  NOT NULL,
          row_json   LONGTEXT NULL,
          deleted    TINYINT(1) NOT NULL DEFAULT 0,
          updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
          PRIMARY KEY (table_name, pk_value)
        ) ENGINE=InnoDB;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sync_changes (
          id BIGINT NOT NULL AUTO_INCREMENT,
          change_uuid CHAR(32) NOT NULL,
          source_device VARCHAR(64) NOT NULL,
          table_name VARCHAR(128) NOT NULL,
          pk_value VARCHAR(64) NOT NULL,
          op ENUM('INSERT','UPDATE','DELETE') NOT NULL,
          row_json LONGTEXT NULL,
          created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
          PRIMARY KEY (id),
          UNIQUE KEY uq_change_uuid (change_uuid),
          KEY idx_created_at (created_at),
          KEY idx_table_pk (table_name, pk_value)
        ) ENGINE=InnoDB;
    ");
}
