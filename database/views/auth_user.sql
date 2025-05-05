CREATE OR REPLACE VIEW user_auth AS
SELECT
    a.id,
    a.username,
    a.role,
    a.status,
    a.token,
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
FROM
    auth a
        INNER JOIN
    user u ON a.id = u.id;