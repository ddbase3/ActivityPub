-- Lokale Benutzer
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    passwordHash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    isPrivate TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ActivityPub Actors (lokale + externe Identitäten)
CREATE TABLE actors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId INT NULL,
    uri VARCHAR(500) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,         -- Person, Service, Application
    inbox VARCHAR(500),
    outbox VARCHAR(500),
    publicKey TEXT,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_actor_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE SET NULL
);

-- ActivityPub Objekte (Notes, Media, etc.)
CREATE TABLE objects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actorId INT NOT NULL,
    uri VARCHAR(500) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,         -- Note, Image, etc.
    content TEXT,
    mediaUrl VARCHAR(500),
    visibility VARCHAR(50) DEFAULT 'public',
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_object_actor FOREIGN KEY (actorId) REFERENCES actors(id) ON DELETE CASCADE
);

-- ActivityPub Activities (Follow, Like, Announce, Create, etc.)
CREATE TABLE activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actorId INT NOT NULL,
    type VARCHAR(50) NOT NULL,         -- Follow, Like, Announce, Create...
    objectId INT NULL,
    targetId INT NULL,
    raw JSON,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_actor FOREIGN KEY (actorId) REFERENCES actors(id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_object FOREIGN KEY (objectId) REFERENCES objects(id) ON DELETE SET NULL,
    CONSTRAINT fk_activity_target FOREIGN KEY (targetId) REFERENCES actors(id) ON DELETE SET NULL
);

-- Follower-Beziehungen
CREATE TABLE followers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actorId INT NOT NULL,              -- wer folgt
    targetId INT NOT NULL,             -- wem gefolgt wird
    accepted BOOLEAN NOT NULL DEFAULT FALSE,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_follower_actor FOREIGN KEY (actorId) REFERENCES actors(id) ON DELETE CASCADE,
    CONSTRAINT fk_follower_target FOREIGN KEY (targetId) REFERENCES actors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_follow (actorId, targetId)
);

-- Ausgehende Activity-Queue (für Retry-Logik)
CREATE TABLE delivery_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activityId INT NOT NULL,
    targetInbox VARCHAR(500) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending', -- pending, delivered, failed
    lastAttempt DATETIME NULL,
    CONSTRAINT fk_delivery_activity FOREIGN KEY (activityId) REFERENCES activities(id) ON DELETE CASCADE
);

