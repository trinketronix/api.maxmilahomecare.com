-- Apply by hand to maxmilah_test, then maxmilah_prod, after merging chore/schema-and-cron.
-- Brings live databases in line with database/tables/*.sql and database/views/*.sql.
-- Every statement is idempotent / safe to re-run.

-- patient.phone2 / phone3 were NOT NULL without a default while the model and API treat them as optional
ALTER TABLE `patient`
    MODIFY `phone2` VARCHAR(20) DEFAULT NULL COMMENT 'Secondary contact phone number, optional',
    MODIFY `phone3` VARCHAR(20) DEFAULT NULL COMMENT 'Third contact phone number, optional';

-- user_auth view: expose expiration and auth_created_at (declared by Api\Models\UserAuthView)
CREATE OR REPLACE VIEW `user_auth` AS
SELECT
    a.id,
    a.username,
    a.role,
    a.status,
    a.token,
    a.expiration,
    a.created_at AS auth_created_at,
    a.updated_at AS auth_updated_at,
    u.firstname,
    u.lastname,
    u.middlename,
    u.birthdate,
    u.ssn,
    u.code,
    u.phone,
    u.phone2,
    u.email,
    u.email2,
    u.languages,
    u.description,
    u.photo,
    u.created_at AS user_created_at,
    u.updated_at AS user_updated_at
FROM auth a
INNER JOIN user u ON a.id = u.id;

-- Only needed if a database was (re)installed from an old reinstall_all.sql that lacked these:
-- ALTER TABLE `visit` ADD COLUMN `extra_minutes` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Visit extra minutes' AFTER `total_hours`;
-- ALTER TABLE `visit` DROP CONSTRAINT `chk_total_hours`, ADD CONSTRAINT `chk_total_hours` CHECK(`total_hours` >= 0 AND `total_hours` <= 24);
