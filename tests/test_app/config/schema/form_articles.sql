CREATE TABLE form_articles (
    id INTEGER PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    published BOOLEAN NOT NULL DEFAULT 0,
    topics TEXT NOT NULL DEFAULT '[]'
);
CREATE TABLE comments (
    id INTEGER PRIMARY KEY,
    article_id INTEGER NOT NULL REFERENCES form_articles(id),
    body VARCHAR(500) NOT NULL,
    approved BOOLEAN NOT NULL DEFAULT 0
);
