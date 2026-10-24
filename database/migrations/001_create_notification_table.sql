
-- Create the notification table for student claim updates.
CREATE TABLE IF NOT EXISTS public.notification (
    id_notification BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_user INTEGER NOT NULL
        REFERENCES public."User"(id_user) ON DELETE CASCADE,
    id_claim INTEGER
        REFERENCES public.claim(id_claim) ON DELETE SET NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITHOUT TIME ZONE
        NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Speed up queries for unread notifications belonging to a student.
CREATE INDEX IF NOT EXISTS idx_notification_user_unread
    ON public.notification (id_user, is_read, created_at DESC);
