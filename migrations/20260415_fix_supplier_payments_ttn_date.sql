UPDATE supplier_payments sp
JOIN (
    SELECT
        sp2.id,
        o.ttn_date AS new_base_date,
        DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY) AS new_due_date,
        CASE DAYOFWEEK(DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY))
            WHEN 1 THEN DATE_SUB(DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY), INTERVAL 3 DAY)
            WHEN 2 THEN DATE_SUB(DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY), INTERVAL 4 DAY)
            WHEN 3 THEN DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY)
            WHEN 4 THEN DATE_SUB(DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY), INTERVAL 1 DAY)
            WHEN 5 THEN DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY)
            WHEN 6 THEN DATE_SUB(DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY), INTERVAL 1 DAY)
            WHEN 7 THEN DATE_SUB(DATE_ADD(o.ttn_date, INTERVAL sp2.payment_delay_days DAY), INTERVAL 2 DAY)
        END AS new_payment_date
    FROM supplier_payments sp2
    JOIN orders o
        ON sp2.order_id COLLATE utf8mb4_unicode_ci = o.id
    WHERE o.ttn_date IS NOT NULL
      AND sp2.delivery_date <> o.ttn_date
) calc ON calc.id = sp.id
SET
    sp.delivery_date = calc.new_base_date,
    sp.payment_due_date = calc.new_due_date,
    sp.payment_date = calc.new_payment_date,
    sp.request_deadline = CONCAT(DATE_SUB(calc.new_payment_date, INTERVAL 1 DAY), ' 15:00:00');
