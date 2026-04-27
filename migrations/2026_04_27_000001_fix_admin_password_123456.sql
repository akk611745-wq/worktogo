UPDATE users
SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9G2C2r8j6Yk5o0r8Qb6j5K',
    auth_type = 'email',
    email = COALESCE(NULLIF(email, ''), 'admin@worktogo.com'),
    status = 'active',
    updated_at = NOW()
WHERE role = 'admin';
