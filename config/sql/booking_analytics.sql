-- Executed in the migration transaction. No customer data enters this projection.
CREATE SEQUENCE booking_analytics_version_seq;
-- statement
CREATE TABLE booking_analytics_state (
    booking_id INT PRIMARY KEY,
    snapshot JSONB NOT NULL
);

-- statement
CREATE FUNCTION enqueue_booking_analytics(payload JSONB) RETURNS VOID LANGUAGE SQL AS $$
    INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
    VALUES (
        jsonb_build_object('snapshot', payload)::text,
        '{"type":"App\\Message\\SyncBookingAnalytics","Content-Type":"application/json"}',
        'analytics', date_trunc('second', CURRENT_TIMESTAMP AT TIME ZONE 'UTC'),
        date_trunc('second', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')
    );
$$;

-- statement
CREATE FUNCTION project_booking_analytics(b booking, removed BOOLEAN DEFAULT FALSE) RETURNS VOID LANGUAGE plpgsql AS $$
DECLARE
    payload JSONB;
    service_name TEXT;
    previous JSONB;
BEGIN
    SELECT snapshot INTO previous FROM booking_analytics_state WHERE booking_id = b.id;
    -- Preserve the original service if the arrival slot did not change, even after opening hours change.
    IF previous IS NOT NULL AND previous->>'hour' = to_char(b.order_hour, 'HH24:MI')
        AND (previous->>'restaurantId')::int = b.restaurant_id THEN
        service_name := previous->>'service';
    ELSE
        SELECT CASE
            WHEN to_char(b.order_hour, 'HH24:MI') >= am_opening_time->>0
                AND to_char(b.order_hour, 'HH24:MI') < am_opening_time->>1 THEN 'lunch'
            WHEN to_char(b.order_hour, 'HH24:MI') >= pm_opening_time->>0
                AND to_char(b.order_hour, 'HH24:MI') < pm_opening_time->>1 THEN 'dinner'
            ELSE 'unknown'
        END INTO service_name FROM restaurant WHERE id = b.restaurant_id;
    END IF;
    payload := jsonb_build_object(
        'bookingId', b.id, 'restaurantId', b.restaurant_id,
        'date', to_char(b.order_date, 'YYYY-MM-DD'), 'hour', to_char(b.order_hour, 'HH24:MI'),
        'service', coalesce(service_name, 'unknown'), 'guests', b.guest_number,
        'createdAt', to_char(b.created_at, 'YYYY-MM-DD"T"HH24:MI:SS'),
        'updatedAt', to_char(b.updated_at, 'YYYY-MM-DD"T"HH24:MI:SS'),
        'deleted', removed, 'version', nextval('booking_analytics_version_seq')
    );
    INSERT INTO booking_analytics_state VALUES (b.id, payload)
        ON CONFLICT (booking_id) DO UPDATE SET snapshot = EXCLUDED.snapshot;
    PERFORM enqueue_booking_analytics(payload);
END;
$$;

-- statement
CREATE FUNCTION capture_booking_analytics() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM project_booking_analytics(OLD, TRUE);
        RETURN OLD;
    END IF;
    PERFORM project_booking_analytics(NEW);
    RETURN NEW;
END;
$$;

-- statement
CREATE TRIGGER booking_analytics_capture AFTER INSERT OR UPDATE OR DELETE ON booking
    FOR EACH ROW EXECUTE FUNCTION capture_booking_analytics();

-- Import existing bookings atomically with installation of the trigger.
-- statement
SELECT project_booking_analytics(b) FROM booking b;
