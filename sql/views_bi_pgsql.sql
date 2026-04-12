-- Dolibarr BI Views — PostgreSQL version
-- Auto-installed by DatabaseSwitch after migration

CREATE OR REPLACE VIEW v_bi_dim_client AS
SELECT s.rowid AS client_id, s.nom AS nom_client, s.name_alias AS alias_client,
    s.code_client, s.address AS adresse, s.zip AS code_postal, s.town AS ville,
    p.label AS pays, p.code AS code_pays, s.phone AS telephone, s.email,
    s.tva_intra, CASE s.status WHEN 0 THEN 'Fermé' WHEN 1 THEN 'Actif' ELSE 'Inconnu' END AS statut,
    CASE s.client WHEN 1 THEN 'Client' WHEN 2 THEN 'Prospect' WHEN 3 THEN 'Client + Prospect' ELSE 'Autre' END AS type_client,
    CASE WHEN s.datec IS NOT NULL AND s.datec > '1900-01-01' THEN s.datec::date ELSE NULL END AS date_creation
FROM llx_societe s LEFT JOIN llx_c_country p ON p.rowid = s.fk_pays
WHERE s.client IN (1, 2, 3);

CREATE OR REPLACE VIEW v_bi_dim_produit AS
SELECT p.rowid AS produit_id, p.ref AS reference, p.label AS nom_produit,
    CASE p.fk_product_type WHEN 0 THEN 'Produit' WHEN 1 THEN 'Service' ELSE 'Autre' END AS type_produit,
    p.price AS prix_ht, p.price_ttc, p.cost_price AS prix_revient, p.pmp, p.tva_tx AS taux_tva,
    p.stock AS stock_actuel, p.desiredstock AS stock_souhaite, p.seuil_stock_alerte AS seuil_alerte,
    CASE p.tosell WHEN 1 THEN 'Oui' ELSE 'Non' END AS en_vente,
    CASE p.tobuy WHEN 1 THEN 'Oui' ELSE 'Non' END AS achetable,
    p.accountancy_code_sell AS compte_vente, p.accountancy_code_buy AS compte_achat,
    CASE WHEN p.datec IS NOT NULL AND p.datec > '1900-01-01' THEN p.datec::date ELSE NULL END AS date_creation
FROM llx_product p;

CREATE OR REPLACE VIEW v_bi_dim_fournisseur AS
SELECT s.rowid AS fournisseur_id, s.nom AS nom_fournisseur, s.code_fournisseur,
    s.code_compta_fournisseur AS code_compta, s.address AS adresse, s.zip AS code_postal,
    s.town AS ville, p.label AS pays, p.code AS code_pays, s.phone AS telephone, s.email,
    s.tva_intra, CASE s.status WHEN 0 THEN 'Fermé' WHEN 1 THEN 'Actif' ELSE 'Inconnu' END AS statut,
    CASE WHEN s.datec IS NOT NULL AND s.datec > '1900-01-01' THEN s.datec::date ELSE NULL END AS date_creation
FROM llx_societe s LEFT JOIN llx_c_country p ON p.rowid = s.fk_pays
WHERE s.fournisseur = 1;

CREATE OR REPLACE VIEW v_bi_dim_date AS
SELECT d.dt::date AS date_id, TO_CHAR(d.dt, 'YYYY-MM-DD') AS date_iso,
    EXTRACT(YEAR FROM d.dt)::int AS annee, EXTRACT(QUARTER FROM d.dt)::int AS trimestre,
    EXTRACT(MONTH FROM d.dt)::int AS mois, EXTRACT(DAY FROM d.dt)::int AS jour,
    TO_CHAR(d.dt, 'YYYY') || '-T' || EXTRACT(QUARTER FROM d.dt) AS annee_trimestre,
    TO_CHAR(d.dt, 'YYYY-MM') AS annee_mois,
    CASE EXTRACT(MONTH FROM d.dt)::int WHEN 1 THEN 'Janvier' WHEN 2 THEN 'Février' WHEN 3 THEN 'Mars' WHEN 4 THEN 'Avril'
        WHEN 5 THEN 'Mai' WHEN 6 THEN 'Juin' WHEN 7 THEN 'Juillet' WHEN 8 THEN 'Août'
        WHEN 9 THEN 'Septembre' WHEN 10 THEN 'Octobre' WHEN 11 THEN 'Novembre' WHEN 12 THEN 'Décembre' END AS nom_mois,
    CASE EXTRACT(DOW FROM d.dt)::int WHEN 0 THEN 'Dimanche' WHEN 1 THEN 'Lundi' WHEN 2 THEN 'Mardi' WHEN 3 THEN 'Mercredi'
        WHEN 4 THEN 'Jeudi' WHEN 5 THEN 'Vendredi' WHEN 6 THEN 'Samedi' END AS jour_semaine,
    EXTRACT(DOW FROM d.dt)::int + 1 AS num_jour_semaine, EXTRACT(WEEK FROM d.dt)::int AS semaine_annee,
    CASE WHEN EXTRACT(DOW FROM d.dt) IN (0,6) THEN 'Weekend' ELSE 'Semaine' END AS type_jour,
    CASE WHEN d.dt = CURRENT_DATE THEN 1 ELSE 0 END AS est_aujourdhui,
    CASE WHEN EXTRACT(YEAR FROM d.dt) = EXTRACT(YEAR FROM CURRENT_DATE) THEN 1 ELSE 0 END AS est_annee_courante
FROM generate_series('2020-01-01'::date, '2030-12-31'::date, '1 day'::interval) d(dt);

CREATE OR REPLACE VIEW v_bi_fact_ventes AS
SELECT fd.rowid AS ligne_id, f.rowid AS facture_id, f.ref AS ref_facture,
    f.fk_soc AS client_id, fd.fk_product AS produit_id,
    CASE WHEN f.datef IS NOT NULL AND f.datef > '1900-01-01' THEN f.datef::date ELSE NULL END AS date_facture,
    CASE WHEN f.date_valid IS NOT NULL AND f.date_valid > '1900-01-01' THEN f.date_valid::date ELSE NULL END AS date_validation,
    CASE WHEN f.date_lim_reglement IS NOT NULL AND f.date_lim_reglement > '1900-01-01' THEN f.date_lim_reglement::date ELSE NULL END AS date_echeance,
    CASE f.fk_statut WHEN 0 THEN 'Brouillon' WHEN 1 THEN 'Validée' WHEN 2 THEN 'Payée' WHEN 3 THEN 'Abandonnée' ELSE 'Autre' END AS statut_facture,
    CASE WHEN f.paye = 1 THEN 'Payée' ELSE 'Non payée' END AS statut_paiement,
    fd.qty AS quantite, fd.subprice AS prix_unitaire_ht, fd.tva_tx AS taux_tva,
    fd.total_ht AS montant_ht, fd.total_tva AS montant_tva, fd.total_ttc AS montant_ttc,
    fd.buy_price_ht AS prix_achat_ht,
    COALESCE(fd.total_ht,0) - (COALESCE(fd.buy_price_ht,0) * COALESCE(fd.qty,0)) AS marge_brute,
    u.lastname AS nom_commercial, f.entity AS entite
FROM llx_facturedet fd JOIN llx_facture f ON f.rowid = fd.fk_facture
LEFT JOIN llx_user u ON u.rowid = f.fk_user_author;

CREATE OR REPLACE VIEW v_bi_fact_achats AS
SELECT ffd.rowid AS ligne_id, ff.rowid AS facture_id, ff.ref AS ref_facture,
    ff.fk_soc AS fournisseur_id, ffd.fk_product AS produit_id,
    CASE WHEN ff.datef IS NOT NULL AND ff.datef > '1900-01-01' THEN ff.datef::date ELSE NULL END AS date_facture,
    CASE ff.fk_statut WHEN 0 THEN 'Brouillon' WHEN 1 THEN 'Validée' WHEN 2 THEN 'Payée' ELSE 'Autre' END AS statut_facture,
    CASE WHEN ff.paye = 1 THEN 'Payée' ELSE 'Non payée' END AS statut_paiement,
    ffd.qty AS quantite, ffd.pu_ht AS prix_unitaire_ht, ffd.tva_tx AS taux_tva,
    ffd.total_ht AS montant_ht, COALESCE(ffd.total_ttc,0) - COALESCE(ffd.total_ht,0) AS montant_tva,
    ffd.total_ttc AS montant_ttc, ff.entity AS entite
FROM llx_facture_fourn_det ffd JOIN llx_facture_fourn ff ON ff.rowid = ffd.fk_facture_fourn;

CREATE OR REPLACE VIEW v_bi_pipeline AS
SELECT p.rowid AS propal_id, p.ref AS ref_propal, p.fk_soc AS client_id,
    CASE WHEN p.datep IS NOT NULL AND p.datep > '1900-01-01' THEN p.datep::date ELSE NULL END AS date_propal,
    CASE WHEN p.fin_validite IS NOT NULL AND p.fin_validite > '1900-01-01' THEN p.fin_validite::date ELSE NULL END AS date_expiration,
    CASE p.fk_statut WHEN 0 THEN 'Brouillon' WHEN 1 THEN 'Validée' WHEN 2 THEN 'Signée' WHEN 3 THEN 'Refusée' WHEN 4 THEN 'Facturée' ELSE 'Autre' END AS statut_propal,
    p.total_ht AS montant_ht, p.total_ttc AS montant_ttc,
    CASE WHEN p.fk_statut IN (2,4) THEN 1 ELSE 0 END AS est_gagnee,
    CASE WHEN p.fk_statut = 3 THEN 1 ELSE 0 END AS est_perdue,
    CASE WHEN p.fk_statut = 1 THEN 1 ELSE 0 END AS est_en_cours,
    u.lastname AS nom_commercial, p.entity AS entite
FROM llx_propal p LEFT JOIN llx_user u ON u.rowid = p.fk_user_author;

CREATE OR REPLACE VIEW v_bi_stock AS
SELECT ps.rowid AS stock_id, ps.fk_product AS produit_id, ps.fk_entrepot AS entrepot_id,
    e.ref AS nom_entrepot, e.town AS ville_entrepot, ps.reel AS quantite_reelle,
    CASE WHEN ps.reel <= 0 THEN 'Rupture' WHEN ps.reel <= COALESCE(p.seuil_stock_alerte,0) THEN 'Alerte' ELSE 'OK' END AS statut_stock,
    COALESCE(p.price,0) * ps.reel AS valeur_stock_vente,
    COALESCE(NULLIF(p.pmp,0), p.cost_price, 0) * ps.reel AS valeur_stock_cout,
    p.ref AS ref_produit, p.label AS nom_produit
FROM llx_product_stock ps JOIN llx_product p ON p.rowid = ps.fk_product
JOIN llx_entrepot e ON e.rowid = ps.fk_entrepot WHERE e.statut = 1;

CREATE OR REPLACE VIEW v_bi_tickets AS
SELECT t.rowid AS ticket_id, t.ref AS ref_ticket, t.subject AS sujet, t.type_code AS type_ticket,
    CASE t.severity_code WHEN 'LOW' THEN 'Basse' WHEN 'NORMAL' THEN 'Normale' WHEN 'HIGH' THEN 'Haute' WHEN 'BLOCKING' THEN 'Bloquante' ELSE COALESCE(t.severity_code,'Non définie') END AS priorite,
    CASE t.fk_statut WHEN 0 THEN 'Non lu' WHEN 1 THEN 'Lu' WHEN 3 THEN 'En cours' WHEN 5 THEN 'Assigné' WHEN 8 THEN 'Résolu' WHEN 9 THEN 'Fermé' ELSE 'Autre' END AS statut_ticket,
    CASE WHEN t.fk_statut IN (8,9) THEN 1 ELSE 0 END AS est_resolu,
    CASE WHEN t.fk_statut NOT IN (8,9) THEN 1 ELSE 0 END AS est_ouvert,
    t.fk_soc AS client_id, s.nom AS nom_client,
    t.fk_user_assign AS id_assignee, ua.lastname AS nom_assignee,
    CASE WHEN t.datec IS NOT NULL AND t.datec > '1900-01-01' THEN t.datec::date ELSE NULL END AS date_creation,
    CASE WHEN t.date_close IS NOT NULL AND t.date_close > '1900-01-01' THEN t.date_close::date ELSE NULL END AS date_cloture,
    TO_CHAR(t.datec, 'YYYY-MM') AS mois_creation,
    CASE WHEN t.date_close IS NOT NULL THEN EXTRACT(DAY FROM (t.date_close - t.datec))::int ELSE NULL END AS delai_resolution_jours,
    t.entity AS entite
FROM llx_ticket t LEFT JOIN llx_societe s ON s.rowid = t.fk_soc
LEFT JOIN llx_user ua ON ua.rowid = t.fk_user_assign;
