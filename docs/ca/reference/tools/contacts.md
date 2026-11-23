<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/contacts.md -->
<!-- translation-source-revision: 43eaec06ad2748a8e6f546fbe8ebf9cd04ba8f52 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/contacts.md).

# Contactes

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Contactes** | llista llibretes d'adreces pròpies o compartides, cerca i llegeix contactes, inclòs el catàleg de comptes de només lectura de la instància (companys, amb `accountId` per a invitacions) segons la configuració d'enumeració d'usuaris de l'administrador | crea i edita preservant els camps vCard desconeguts; suprimeix definitivament només després d'una còpia `.vcf` verificada sota `Contacts` a la carpeta de còpies configurada (predeterminada `/MCP backups/Contacts`; obligatòria encara que se suspenguin les còpies de Files) |

[Funcionament de Contactes i Tasques](contacts-tasks.md).

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

| Mòdul | Eines | Dependència |
| --- | --- | --- |
| Contactes | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | aplicació Contacts activada; CardDAV del nucli |

Les eines de Contactes requereixen que l'aplicació Contacts estigui activada per a l'usuari. La lectura s'autoritza inicialment; l'administrador ha de concedir els permisos de creació, edició i supressió.

[Guies operatives](../../guides/README.md).

## Cercar i mantenir fitxes de contacte

- Fes servir `contacts_list_addressbooks` per localitzar les llibretes personals i compartides i, si l’administració el fa visible, el directori de comptes de només lectura.
- Cerca amb `contacts_search_contacts` i consulta la fitxa triada amb `contacts_read_contact`. Si necessites un compte de Nextcloud per convidar algú, fes servir l’`accountId` retornat quan estigui disponible.
- Fes servir `contacts_create_contact` per afegir una fitxa o `contacts_edit_contact` per modificar-la en una llibreta amb permís d’escriptura. `contacts_delete_contact` verifica una còpia vCard abans de suprimir la fitxa i retorna el camí de la còpia.
- Revisa la previsualització i confirma el mateix canvi. Per editar o suprimir una fitxa, inclou l’ETag del pla. Si la llibreta és compartida, confirma també el canvi a la llibreta compartida amb `confirm_shared: true`.

## Grups, llibretes i vCard

`contacts_list_groups` llista les categories d'una llibreta visible. Passa `group` a `contacts_search_contacts` per obtenir una coincidència exacta de categoria. En crear i editar s'admeten noms i adreces estructurats, categories, aniversari i correu preferit. Els camps segueixen la versió vCard desada.

`contacts_export_vcf` retorna les fitxes seleccionades com a document vCard; desa'l com a fitxer `.vcf`. Fes servir `contacts_import_vcf` amb un fitxer que puguis llegir a Files o amb el camí de la còpia de seguretat retornat en suprimir un contacte. El pla indica quines fitxes es crearan o s'ometran. Una nova importació amb un pla actualitzat omet els UID que ja són a la llibreta.

`contacts_find_duplicates` troba possibles duplicats quan coincideixen els correus o telèfons normalitzats. Un nom i una organització compartits són indicis menys concloents. Revisa les referències i els ETag abans de decidir quines fitxes consolidar.

Fes servir `contacts_merge_contacts` amb fins a cinc grups sense fitxes compartides. Cada grup conté de dues a deu fitxes d'una mateixa llibreta editable. Tria quina es conserva i revisa els valors proposats per a cada conflicte. Després d'aprovar-ho, repeteix la crida amb els ETag i el `plan_state` de la vista prèvia. L'eina verifica les còpies originals i la fitxa consolidada abans d'eliminar les fonts. Indica els grups pendents de revisió i els seus camins de còpia de seguretat.

## Llibretes i trasllat de contactes

Fes servir `contacts_create_addressbook` per crear una llibreta personal i `contacts_list_addressbook_shares` per consultar els permisos d'una llibreta pròpia. `contacts_share_addressbook` gestiona l'accés directe d'usuaris locals: configura accés de lectura o escriptura, o retira un accés existent, i revisa el canvi a la vista prèvia.

`contacts_copy_contact` crea un contacte diferent en una destinació editable i manté l'original. `contacts_move_contact` conserva l'UID, verifica la destinació i desa una còpia de seguretat abans d'eliminar la font. Confirma el trasllat amb el `plan_state` del pla. Inclou l'ETag de la font en moure i comprova l'estat de les dues fitxes si falla algun pas. Les llibretes compartides requereixen una aprovació separada per a cada col·lecció afectada.

## Manteniment per lots

`contacts_update_categories` afegeix, retira o canvia el nom d'una categoria a les fitxes seleccionades d'una llibreta editable. Indica els ETag de les fitxes. La vista prèvia mostra les categories abans i després; el resultat informa de l'estat de cada fitxa.

`contacts_reconcile_vcf` actualitza les fitxes amb una coincidència UID exacta i única en un VCF visible a Files. Revisa els valors actuals, proposats i conservats. Cada actualització requereix una còpia de seguretat completa verificada. Les fitxes sense una coincidència única queden pendents de revisió. Confirma cada operació amb el seu `plan_state` i reconeix per separat les escriptures a llibretes compartides.

[English](../../../en/reference/tools/contacts.md) · [Español](../../../es/reference/tools/contacts.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/contacts.md) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts.md)
