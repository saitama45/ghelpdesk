-- Read-only SQL Server audit for duplicate TGI Work Tools submissions.
-- This script does not update or delete any records.

SET NOCOUNT ON;

DECLARE @FormId bigint = (
    SELECT TOP (1) id
    FROM form_definitions
    WHERE slug = 'tgi-work-tools'
);

-- Confirm that the dynamic form exists and show its identifier.
SELECT id, name, slug, approval_levels, workflow_type
FROM form_definitions
WHERE id = @FormId;

-- Inspect the reported sequence. payload_hash/data_bytes confirm whether the
-- JSON payloads are byte-for-byte identical without printing personal data.
SELECT
    fr.id,
    fr.request_type_id,
    fr.created_by,
    fr.status,
    fr.ticket_id,
    fr.created_at,
    fr.updated_at,
    DATALENGTH(fr.data) AS data_bytes,
    CONVERT(varchar(64), HASHBYTES('SHA2_256', CONVERT(varbinary(max), fr.data)), 2) AS payload_hash
FROM form_records AS fr
WHERE fr.form_definition_id = @FormId
  AND fr.id BETWEEN 76 AND 95
ORDER BY fr.id;

-- Find every exact-payload duplicate group for this form. Groups created within
-- a short interval with the same creator/request type and missing ticket IDs are
-- strong evidence of repeated HTTP submissions.
WITH record_signatures AS (
    SELECT
        fr.id,
        fr.request_type_id,
        fr.created_by,
        fr.status,
        fr.ticket_id,
        fr.created_at,
        DATALENGTH(fr.data) AS data_bytes,
        HASHBYTES('SHA2_256', CONVERT(varbinary(max), fr.data)) AS payload_hash
    FROM form_records AS fr
    WHERE fr.form_definition_id = @FormId
),
duplicate_groups AS (
    SELECT
        request_type_id,
        created_by,
        status,
        data_bytes,
        payload_hash,
        COUNT(*) AS duplicate_count,
        MIN(id) AS keep_candidate_id,
        MIN(created_at) AS first_created_at,
        MAX(created_at) AS last_created_at,
        SUM(CASE WHEN ticket_id IS NULL THEN 1 ELSE 0 END) AS missing_ticket_count
    FROM record_signatures
    GROUP BY request_type_id, created_by, status, data_bytes, payload_hash
    HAVING COUNT(*) > 1
)
SELECT
    duplicate_count,
    keep_candidate_id,
    first_created_at,
    last_created_at,
    DATEDIFF(SECOND, first_created_at, last_created_at) AS span_seconds,
    missing_ticket_count,
    request_type_id,
    created_by,
    status,
    data_bytes,
    CONVERT(varchar(64), payload_hash, 2) AS payload_hash
FROM duplicate_groups
ORDER BY last_created_at DESC;

-- List the member IDs of every duplicate group for record-by-record review.
WITH record_signatures AS (
    SELECT
        fr.id,
        fr.request_type_id,
        fr.created_by,
        fr.status,
        fr.ticket_id,
        fr.created_at,
        DATALENGTH(fr.data) AS data_bytes,
        HASHBYTES('SHA2_256', CONVERT(varbinary(max), fr.data)) AS payload_hash
    FROM form_records AS fr
    WHERE fr.form_definition_id = @FormId
),
duplicate_groups AS (
    SELECT request_type_id, created_by, status, data_bytes, payload_hash
    FROM record_signatures
    GROUP BY request_type_id, created_by, status, data_bytes, payload_hash
    HAVING COUNT(*) > 1
)
SELECT
    rs.id,
    rs.created_at,
    rs.ticket_id,
    rs.status,
    rs.request_type_id,
    rs.created_by,
    CONVERT(varchar(64), rs.payload_hash, 2) AS payload_hash
FROM record_signatures AS rs
INNER JOIN duplicate_groups AS dg
    ON (dg.request_type_id = rs.request_type_id OR (dg.request_type_id IS NULL AND rs.request_type_id IS NULL))
   AND (dg.created_by = rs.created_by OR (dg.created_by IS NULL AND rs.created_by IS NULL))
   AND dg.status = rs.status
   AND dg.data_bytes = rs.data_bytes
   AND dg.payload_hash = rs.payload_hash
ORDER BY rs.created_at DESC, rs.id DESC;

