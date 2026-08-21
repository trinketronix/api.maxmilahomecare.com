-- Drops every object created by reinstall_all.sql (views first, then tables in FK order).
DROP VIEW IF EXISTS `user_auth`;
DROP VIEW IF EXISTS `ordered_visits`;
DROP TABLE IF EXISTS `visit`;
DROP TABLE IF EXISTS `user_patient`;
DROP TABLE IF EXISTS `address`;
DROP TABLE IF EXISTS `user`;
DROP TABLE IF EXISTS `auth`;
DROP TABLE IF EXISTS `patient`;
DROP TABLE IF EXISTS `tool`;
