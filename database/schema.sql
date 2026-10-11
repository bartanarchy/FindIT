--
-- PostgreSQL database dump
--

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

-- Started on 2026-10-10 19:02:09

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';
SET default_table_access_method = heap;

CREATE TABLE public."User" (
    id_user integer NOT NULL,
    email_kampus character varying(100) NOT NULL,
    name_user character varying(50) NOT NULL,
    password_user character varying(255) NOT NULL,
    no_telp character varying(15) NOT NULL,
    role_user character varying(20) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    id_nim character varying(20)
);

ALTER TABLE public."User" OWNER TO postgres;

CREATE SEQUENCE public."User_id_user_seq"
    AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public."User_id_user_seq" OWNER TO postgres;
ALTER SEQUENCE public."User_id_user_seq" OWNED BY public."User".id_user;

CREATE TABLE public.admin (
    id_admin integer NOT NULL,
    email_admin character varying(100) NOT NULL,
    nama_admin character varying(50) NOT NULL,
    password_admin character varying(255) NOT NULL,
    level_access character varying(20) NOT NULL
);

ALTER TABLE public.admin OWNER TO postgres;

CREATE SEQUENCE public.admin_id_admin_seq
    AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.admin_id_admin_seq OWNER TO postgres;
ALTER SEQUENCE public.admin_id_admin_seq OWNED BY public.admin.id_admin;

CREATE TABLE public.category (
    id_category integer NOT NULL,
    name_category character varying(50) NOT NULL
);

ALTER TABLE public.category OWNER TO postgres;

CREATE SEQUENCE public.category_id_category_seq
    AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.category_id_category_seq OWNER TO postgres;
ALTER SEQUENCE public.category_id_category_seq OWNED BY public.category.id_category;

CREATE TABLE public.claim (
    id_claim integer NOT NULL,
    kode_klaim character varying(50) NOT NULL,
    id_item integer,
    id_user_pengaju integer,
    id_admin_verifikator integer,
    lokasi_kehilangan text,
    terakhir_di_lihat text,
    ciri_khusus_claim text,
    catatan_admin text,
    status_klaim character varying(20),
    kode_pengambilan character varying(50),
    lokasi_pengambilan character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE public.claim OWNER TO postgres;

CREATE SEQUENCE public.claim_id_claim_seq
    AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.claim_id_claim_seq OWNER TO postgres;
ALTER SEQUENCE public.claim_id_claim_seq OWNED BY public.claim.id_claim;

CREATE TABLE public.item (
    kode_laporan integer NOT NULL,
    id_item integer NOT NULL,
    id_user_pelapor integer NOT NULL,
    id_kategori integer NOT NULL,
    nama_barang character varying(100) NOT NULL,
    detail_lokasi text,
    tanggal_ditemukan date,
    ciri_umum text,
    ciri_khusus text,
    photo_url bytea,
    status_barang character varying(20) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    userid_user integer,
    locationid_location integer
);

ALTER TABLE public.item OWNER TO postgres;

CREATE SEQUENCE public.item_id_item_seq
    AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.item_id_item_seq OWNER TO postgres;
ALTER SEQUENCE public.item_id_item_seq OWNED BY public.item.id_item;

CREATE SEQUENCE public.item_kode_laporan_seq
    AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.item_kode_laporan_seq OWNER TO postgres;
ALTER SEQUENCE public.item_kode_laporan_seq OWNED BY public.item.kode_laporan;

CREATE TABLE public.location (
    id_location integer NOT NULL,
    tempat_ditemukan character varying(255) NOT NULL
);

ALTER TABLE public.location OWNER TO postgres;

CREATE SEQUENCE public.location_id_location_seq
    AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.location_id_location_seq OWNER TO postgres;
ALTER SEQUENCE public.location_id_location_seq OWNED BY public.location.id_location;

ALTER TABLE ONLY public."User" ALTER COLUMN id_user SET DEFAULT nextval('public."User_id_user_seq"'::regclass);
ALTER TABLE ONLY public.admin ALTER COLUMN id_admin SET DEFAULT nextval('public.admin_id_admin_seq'::regclass);
ALTER TABLE ONLY public.category ALTER COLUMN id_category SET DEFAULT nextval('public.category_id_category_seq'::regclass);
ALTER TABLE ONLY public.claim ALTER COLUMN id_claim SET DEFAULT nextval('public.claim_id_claim_seq'::regclass);
ALTER TABLE ONLY public.item ALTER COLUMN kode_laporan SET DEFAULT nextval('public.item_kode_laporan_seq'::regclass);
ALTER TABLE ONLY public.item ALTER COLUMN id_item SET DEFAULT nextval('public.item_id_item_seq'::regclass);
ALTER TABLE ONLY public.location ALTER COLUMN id_location SET DEFAULT nextval('public.location_id_location_seq'::regclass);

-- Data section intentionally left empty (no rows in dump)

SELECT pg_catalog.setval('public."User_id_user_seq"', 1, false);
SELECT pg_catalog.setval('public.admin_id_admin_seq', 1, false);
SELECT pg_catalog.setval('public.category_id_category_seq', 1, false);
SELECT pg_catalog.setval('public.claim_id_claim_seq', 1, false);
SELECT pg_catalog.setval('public.item_id_item_seq', 1, false);
SELECT pg_catalog.setval('public.item_kode_laporan_seq', 1, false);
SELECT pg_catalog.setval('public.location_id_location_seq', 1, false);

ALTER TABLE ONLY public."User" ADD CONSTRAINT "User_email_kampus_key" UNIQUE (email_kampus);
ALTER TABLE ONLY public."User" ADD CONSTRAINT "User_id_nim_key" UNIQUE (id_nim);
ALTER TABLE ONLY public."User" ADD CONSTRAINT "User_pkey" PRIMARY KEY (id_user);

ALTER TABLE ONLY public.admin ADD CONSTRAINT admin_email_admin_key UNIQUE (email_admin);
ALTER TABLE ONLY public.admin ADD CONSTRAINT admin_pkey PRIMARY KEY (id_admin);

ALTER TABLE ONLY public.category ADD CONSTRAINT category_pkey PRIMARY KEY (id_category);

ALTER TABLE ONLY public.claim ADD CONSTRAINT claim_kode_klaim_key UNIQUE (kode_klaim);
ALTER TABLE ONLY public.claim ADD CONSTRAINT claim_pkey PRIMARY KEY (id_claim);

ALTER TABLE ONLY public.item ADD CONSTRAINT item_id_item_key UNIQUE (id_item);
ALTER TABLE ONLY public.item ADD CONSTRAINT item_pkey PRIMARY KEY (kode_laporan);

ALTER TABLE ONLY public.location ADD CONSTRAINT location_pkey PRIMARY KEY (id_location);

ALTER TABLE ONLY public.claim
    ADD CONSTRAINT fk_claim_admin FOREIGN KEY (id_admin_verifikator) REFERENCES public.admin(id_admin) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE ONLY public.claim
    ADD CONSTRAINT fk_claim_item FOREIGN KEY (id_item) REFERENCES public.item(kode_laporan) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE ONLY public.claim
    ADD CONSTRAINT fk_claim_user FOREIGN KEY (id_user_pengaju) REFERENCES public."User"(id_user) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE ONLY public.item
    ADD CONSTRAINT fk_item_category FOREIGN KEY (id_kategori) REFERENCES public.category(id_category) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE ONLY public.item
    ADD CONSTRAINT fk_item_location FOREIGN KEY (locationid_location) REFERENCES public.location(id_location) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE ONLY public.item
    ADD CONSTRAINT fk_item_user FOREIGN KEY (id_user_pelapor) REFERENCES public."User"(id_user) ON UPDATE CASCADE ON DELETE SET NULL;

-- Completed on 2026-10-10 19:02:09
