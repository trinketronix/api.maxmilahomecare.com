-- Drop view if exists
DROP VIEW IF EXISTS `ordered_visits`;

-- Create view for visits with custom ordering
CREATE VIEW `ordered_visits` AS
SELECT
    v.*,
    CASE
        WHEN v.visit_date = CURDATE() AND v.progress = 1 THEN 1  -- Today's in-progress
        WHEN v.visit_date = CURDATE() AND v.progress = 0 THEN 2  -- Today's scheduled
        WHEN v.visit_date > CURDATE() AND v.progress = 0 THEN 3  -- Future scheduled
        WHEN v.progress = -1 THEN 4                              -- Canceled
        ELSE 5                                                   -- Past visits
        END AS sort_order
FROM visit v
ORDER BY
    sort_order,
    v.visit_date DESC,
    v.start_time DESC;