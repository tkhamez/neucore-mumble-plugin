create table if not exists ticker
(
    filter varchar(45) not null primary key,
    text   varchar(5)  not null
) engine = InnoDB charset = utf8mb4;

create table if not exists user
(
    character_id     int               not null primary key,
    character_name   varchar(255)      not null,
    corporation_id   int               not null,
    corporation_name varchar(255)      not null,
    alliance_id      int               null,
    alliance_name    varchar(255)      null,
    mumble_username  varchar(45)       not null,
    mumble_password  varchar(45)       not null,
    created_at       int               not null,
    updated_at       int               not null,
    `groups`         text              null,
    owner_hash       varchar(45)       not null,
    mumble_fullname  varchar(255)      not null,
    avatar           blob              null,
    account_active   tinyint default 1 null
) engine = InnoDB charset = utf8mb4;

create table if not exists ban
(
    filter          varchar(45) not null primary key,
    reason_public   text        null,
    reason_internal text        null
) engine = InnoDB charset = utf8mb4;
