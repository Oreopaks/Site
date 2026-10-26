PRAGMA foreign_keys=ON;
PRAGMA journal_mode=WAL;

CREATE TABLE settings (
  key          TEXT PRIMARY KEY,
  value        TEXT NOT NULL DEFAULT '',
  grp          TEXT NOT NULL,     -- seo|ld|contacts|requisites|hero|texts|legal|metrika|mail
  label        TEXT NOT NULL,
  type         TEXT NOT NULL DEFAULT 'text' CHECK(type IN ('text','textarea')),
  trusted_html INTEGER NOT NULL DEFAULT 0,
  required     INTEGER NOT NULL DEFAULT 0,
  sort         INTEGER NOT NULL DEFAULT 0
);

-- один список услуг: секция + флаги формы и подвала (решение заказчика 2026-10-26)
CREATE TABLE services (
  id INTEGER PRIMARY KEY, sort INTEGER NOT NULL, title TEXT NOT NULL, text TEXT NOT NULL,
  href TEXT NOT NULL DEFAULT '#services',
  in_form INTEGER NOT NULL DEFAULT 0,
  in_footer INTEGER NOT NULL DEFAULT 0);

CREATE TABLE fleet_cards (
  id INTEGER PRIMARY KEY, sort INTEGER NOT NULL, title TEXT NOT NULL, img TEXT NOT NULL, alt TEXT NOT NULL,
  img_pos TEXT NOT NULL DEFAULT '',
  price_kind TEXT NOT NULL CHECK(price_kind IN ('fixed','from','contract')),
  price INTEGER, unit TEXT NOT NULL DEFAULT '');

CREATE TABLE fleet_prices (
  id INTEGER PRIMARY KEY, sort INTEGER NOT NULL, name TEXT NOT NULL, model TEXT NOT NULL DEFAULT '',
  price_kind TEXT NOT NULL CHECK(price_kind IN ('fixed','from','contract')),
  price INTEGER, unit TEXT NOT NULL DEFAULT '', is_sub INTEGER NOT NULL DEFAULT 0);

CREATE TABLE materials (
  id INTEGER PRIMARY KEY, sort INTEGER NOT NULL, title TEXT NOT NULL, text TEXT NOT NULL,
  img TEXT NOT NULL, alt TEXT NOT NULL, badge TEXT NOT NULL DEFAULT '',
  price_kind TEXT NOT NULL CHECK(price_kind IN ('fixed','from','contract')),
  price INTEGER, unit TEXT NOT NULL DEFAULT '');

CREATE TABLE items (
  id INTEGER PRIMARY KEY,
  kind TEXT NOT NULL CHECK(kind IN ('why','step','nav','marquee','stat_hero','stat_bar')),
  sort INTEGER NOT NULL,
  title TEXT NOT NULL, text TEXT NOT NULL DEFAULT '', href TEXT NOT NULL DEFAULT '',
  num INTEGER, suffix TEXT NOT NULL DEFAULT '');
CREATE INDEX items_kind ON items(kind, sort);

CREATE TABLE users (
  id INTEGER PRIMARY KEY, login TEXT NOT NULL UNIQUE, pass_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN ('owner','manager','editor')),
  is_active INTEGER NOT NULL DEFAULT 1,
  pw_ver INTEGER NOT NULL DEFAULT 0,  -- +1 при смене пароля и вкл/выкл: старые сессии недействительны
  created_at TEXT NOT NULL DEFAULT (datetime('now')));

CREATE TABLE login_attempts (login TEXT PRIMARY KEY, fails INTEGER NOT NULL DEFAULT 0, locked_until TEXT);

CREATE TABLE leads (
  id INTEGER PRIMARY KEY,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),   -- = момент согласия
  source TEXT NOT NULL CHECK(source IN ('calc','call','material')),
  name TEXT NOT NULL, phone TEXT NOT NULL,
  service TEXT NOT NULL DEFAULT '', material TEXT NOT NULL DEFAULT '', comment TEXT NOT NULL DEFAULT '',
  consent INTEGER NOT NULL CHECK(consent=1),
  consent_rev TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','in_work','done','spam')),
  mail_error TEXT);
CREATE INDEX leads_status ON leads(status, created_at);

CREATE TABLE lead_events (
  id INTEGER PRIMARY KEY, lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id), at TEXT NOT NULL DEFAULT (datetime('now')),
  from_status TEXT NOT NULL, to_status TEXT NOT NULL);

CREATE TABLE stats (
  day TEXT NOT NULL, page TEXT NOT NULL, ref_domain TEXT NOT NULL DEFAULT '',
  ua_class TEXT NOT NULL CHECK(ua_class IN ('desktop','mobile','bot','other')),
  hits INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (day, page, ref_domain, ua_class));

CREATE TABLE consent_stats (
  day TEXT NOT NULL, choice TEXT NOT NULL CHECK(choice IN ('accept','reject')),
  text_rev TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (day, choice, text_rev));

CREATE TABLE audit_log (
  id INTEGER PRIMARY KEY, at TEXT NOT NULL DEFAULT (datetime('now')),
  user_id INTEGER NOT NULL REFERENCES users(id),
  tbl TEXT NOT NULL, row_key TEXT NOT NULL, old_value TEXT, new_value TEXT);
CREATE INDEX audit_at ON audit_log(at);

CREATE TABLE send_window (bucket TEXT PRIMARY KEY, n INTEGER NOT NULL);
