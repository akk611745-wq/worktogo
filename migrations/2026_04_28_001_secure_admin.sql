UPDATE users
SET password = '$2y$10$ZLIV9S65OTe6/MlNVwKMf.0HXtKErzXYUMOZId0RGmxniYq00f.wK',
    auth_type = 'email',
    status = 'active',
    role = 'admin',
    updated_at = NOW()
WHERE email = 'admin@worktogo.com'
LIMIT 1;
