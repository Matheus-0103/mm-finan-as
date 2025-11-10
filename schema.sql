CREATE DATABASE IF NOT EXISTS mmfinancas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mmfinancas_db;

-- Remover tabelas existentes para evitar erro na importação
DROP TABLE IF EXISTS verification_codes;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS videos;
DROP TABLE IF EXISTS feedbacks;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS group_memberships;
DROP TABLE IF EXISTS groups;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'manager') DEFAULT 'user',
    manager_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_manager (manager_id)
) ENGINE=InnoDB;

-- Tabela de grupos familiares
CREATE TABLE groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    owner_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- Tabela de membros dos grupos
CREATE TABLE group_memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    added_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_membership (group_id, user_id),
    INDEX idx_group (group_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- Tabela de categorias
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    icon VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabela de contas/despesas
CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    group_id INT NULL,
    category_id INT NOT NULL,
    value DECIMAL(10, 2) NOT NULL,
    date DATE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_user (user_id),
    INDEX idx_group (group_id),
    INDEX idx_date (date),
    INDEX idx_category (category_id)
) ENGINE=InnoDB;

-- Tabela de feedbacks
CREATE TABLE feedbacks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    manager_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_manager (manager_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Tabela de vídeos
CREATE TABLE videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manager_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    url VARCHAR(500),
    filename VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_manager (manager_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Tabela de logs
CREATE TABLE logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    meta JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Tabela de códigos de verificação
CREATE TABLE verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    type ENUM('email', 'password') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_verification_user_type (user_id, type, used),
    INDEX idx_verification_expires (expires_at)
) ENGINE=InnoDB;

-- Tabela de controle de rate limit
CREATE TABLE rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_rate_limits_user_action (user_id, action, timestamp)
) ENGINE=InnoDB;

-- Eventos de limpeza automática
DELIMITER //

CREATE EVENT IF NOT EXISTS clean_expired_codes
    ON SCHEDULE EVERY 1 DAY
    DO 
    BEGIN
        DELETE FROM verification_codes WHERE expires_at < NOW() OR used = 1;
    END //

CREATE EVENT IF NOT EXISTS clean_old_rate_limits
    ON SCHEDULE EVERY 1 HOUR
    DO 
    BEGIN
        DELETE FROM rate_limits WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 DAY);
    END //

DELIMITER ;

-- Ativa o Event Scheduler
SET GLOBAL event_scheduler = ON;

-- Inserir categorias padrão
INSERT INTO categories (name, slug, icon) VALUES
('Entretenimento', 'entretenimento', '🎬'),
('Moradia', 'moradia', '🏠'),
('Mercado/Alimentação', 'mercado', '🛒'),
('Colégio', 'colegio', '🎓'),
('Vestimenta', 'vestimenta', '👔'),
('Transporte', 'transporte', '🚗'),
('Saúde', 'saude', '⚕️'),
('Outros', 'outros', '📌');

-- Criar usuário gestor padrão (senha: manager123)
INSERT INTO users (name, email, password_hash, role) VALUES
('Gestor MM Finanças', 'gestor@mmfinancas.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager');

-- Criar usuário de teste (senha: user123)
INSERT INTO users (name, email, password_hash, role, manager_id) VALUES
('João Silva', 'joao@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1);