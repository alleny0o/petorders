# PETOrders Documentation

PETOrders is the NIH PET Department's internal web application for
ordering radiopharmaceutical products. Lab members (customers) place
orders for products by nuclide. PET Department staff process those
orders through a simple lifecycle (pending, accepted, completed,
cancelled).
Administrators manage accounts, the product catalog, the
institute/lab/PI directory, and reporting.

## Tech stack at a glance

|                 |                                                                                                                            |
| --------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Language        | PHP 7.4, no framework, no ORM (PDO with prepared statements)                                                               |
| Database        | MySQL 8.0 / MariaDB 10.11 (wire-compatible via PDO)                                                                        |
| Frontend        | Vanilla CSS and JavaScript, no build step, no bundler                                                                      |
| Dependencies    | **None.** No Composer, no npm, no CDN. Every asset is local, and the app makes no outbound requests (it never sends email) |
| Target platform | RHEL 8 + Apache + HTTPS (local dev on MAMP)                                                                                |

Only `public/` is served by Apache — `src/` (application code and DB
credentials), `sql/`, `tools/`, and `config/` sit above the web root and
are unreachable by URL. The data model is diagrammed in
[`sql/EER Diagram.webp`](<sql/EER Diagram.webp>).

## The three roles

| Role     | Does                                                                                                                       |
| -------- | -------------------------------------------------------------------------------------------------------------------------- |
| Customer | A lab member: places orders, tracks their lab's orders, maintains the lab's delivery locations and product users           |
| Staff    | Processes any order from any lab: accept, complete, return, cancel, reopen, plus notes and the chargeable flag             |
| Admin    | Everything staff can do, plus registration approval, account management, catalog and directory management, and CSV reports |

## Documentation

| Document                                           | Audience             | What's in it                                                                                                                           |
| -------------------------------------------------- | -------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| [Deployment Guide](docs/DEPLOYMENT.md)             | IT / sysadmin        | Step-by-step first-time production deployment on RHEL, prerequisites through verification checklist                                    |
| [Local Dev Setup](docs/LOCAL_DEV_SETUP.md)         | Developer            | Local development environment (MAMP), seeded test database, and dev-only tooling                                                       |
| [Architecture & Conventions](docs/ARCHITECTURE.md) | Developer            | How the app is built: role model, catalog and order state machine, key conventions, and the gotchas worth knowing before changing code |
| [Customer Guide](docs/USER_GUIDE_CUSTOMER.md)      | Lab members          | Registering, logging in, placing and managing orders, delivery locations, and product users                                            |
| [Staff Guide](docs/USER_GUIDE_STAFF.md)            | PET Department staff | The Order Queue and every order action: accept, return, complete, cancel, reopen, chargeable, notes                                    |
| [Admin Guide](docs/USER_GUIDE_ADMIN.md)            | Administrators       | Registrations, customer and staff accounts, catalog (nuclides/products), directory (institutes/labs/PIs), and reports                  |

Screenshots referenced by the user guides live in
[`docs/images/`](docs/images/), organized by area (`customer/`,
`staff/`, `admin/`, `deployment/`, `architecture/`).
