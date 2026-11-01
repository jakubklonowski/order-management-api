# Order & Inventory Management API
[![CI](https://github.com/jakubklonowski/order-management-api/actions/workflows/ci.yml/badge.svg)](https://github.com/jakubklonowski/order-management-api/actions/workflows/ci.yml)

A REST API for e-commerce order and inventory management.

Stack: Symfony 7.4, Doctrine ORM 3, MySQL 8.4, Composer, PHP 8.3, PHPUnit 12,
LexikJWTAuthenticationBundle, NelmioApiDocBundle (OpenAPI 3), Symfony Messenger (Doctrine transport),
Symfony Mailer with Mailpit, Monolog, PHP-CS-Fixer, PHPStan (level 8),
running entirely in Docker.

The domain is deliberately ordinary — categories, products, stock, orders. Four things worth a closer look:

- **Stock is locked, not checked.** `SELECT ... FOR UPDATE` in sorted product order to rule out deadlocks, plus a refresh hint because Doctrine's identity map handed back a stale row after the wait — and a concurrency test that proves the request actually blocked. → [Decisions](#decisions)
- **A message can't outlive its order.** The queue is a table in the same database as the orders. Saving an order and queuing its message happen in one transaction, so either both are saved or neither is. No extra process is needed to forward messages (transactional outbox pattern). A test queues a message inside a transaction that fails and checks that the queue stays empty. → [Decisions](#decisions)
- **An N+1 that no serializer group could fix.** The inverse side of a one-to-one is queried during hydration, before groups are consulted. Measured: one extra query per product without the join, none with it, pinned by a test that counts statements. → [Decisions](#decisions)
- **Tests checked by breaking the code they guard.** Each assertion verified by hand: mutate the code, confirm the named test — and only that test — fails. It repeatedly caught tests that were green for the wrong reason. → [Tests](#tests-and-quality)

Everything described below is running code.

[Jakub Klonowski](https://www.linkedin.com/in/jakubklonowski/) is the author.

## Project contents

Twenty endpoints were created; all except register and login require a JWT.
Their OpenAPI documentation is public.

| resource | endpoints | access |
|---|---|---|
| docs | `GET /api/doc` (Swagger UI), `GET /api/doc.json` (the spec) | public |
| auth | `POST /api/register`, `POST /api/login` | public |
| categories | `GET` list & one, `POST`, `PUT /{id}`, `DELETE /{id}` | read: any user · write: admin |
| products | `GET` list & one, `POST`, `PUT /{id}`, `DELETE /{id}` | read: any user · write: admin |
| inventory | `GET`, `PUT /api/inventory/{productId}` | admin |
| orders | `POST /api/orders`, `GET` list & one, `GET /{id}/history`, `POST /{id}/cancel` | owner or admin |
| | `PUT /api/orders/{id}/status` | admin |

Additionally, an `app:create-admin` console command was created, as registration through the API can only create a customer, not an admin.

### The stock model

Inventory tracks two numbers:
- `quantity` - what physically exists
- `reservedQuantity` - what placed orders have claimed

Available stock is the difference, and that is what a new order is checked against.

Placing an order raises the reservation while cancelling releases it. Shipping is the only
operation that lowers `quantity`, and it lowers the reservation by the same amount.
Stock cannot be sold twice, and a cancelled order returns its stock.

Status moves `pending → confirmed → shipped`, with `cancelled` reachable from `pending`
and `confirmed` only. The transition table is in the enum, `Order::changeStatus()` is
the only method that can move the status, and every move writes a row to `order_status_history`.

### Mailer

Two emails are sent by a Messenger worker, not during the request:

- **Order confirmation**, to customer when order is placed.
- **Low-stock alert**, to every admin when stock reaches its threshold (available <= threshold).

Low stock alert fires on treshold *crossing*: when an order or a `PUT /api/inventory/{productId}` takes available
stock from above the threshold to equal to treshold or below it. Stock that stays low sends nothing, and neither
does creating a product's first stock record, which has no earlier state to cross from. Shipping and
cancelling cannot cross the threshold.

[Mailpit](http://localhost:8025) catches everything the app sends.

## How to run

Running this program requires **only Docker**, which runs PHP, Composer and MySQL.
If you're using Windows, run these from Git Bash.

First, clone the repository:
```bash
git clone https://github.com/jakubklonowski/order-management-api
```

Enter the project directory:
```bash
cd order-management-api
```

Run the setup script; it takes the admin account's email address as its parameter:
```bash
./bin/setup.sh admin@example.com # pass admin account email
```

What `setup.sh` does:
- starts the containers
- installs dependencies
- migrates the dev and test databases
- generates the JWT keypair
- restarts the queue worker, which crash-loops on a fresh clone until the dependencies and the queue table exist
- creates the admin account - setup asks for the password twice here before finishing its work
- prints the URLs, Mailpit's included

Every step is safe to repeat, so re-running is not a problem.

The JWT keypair in `config/jwt/*.pem` is gitignored, so a fresh clone has none and needs
a new one generated, or `/api/login` will fail.

After setup, the API is available at **http://localhost:8080**, and its interactive
documentation at **http://localhost:8080/api/doc**.
To use another port, change the `NGINX_PORT` value in `.env`.
Adminer is on port `8081` for browsing the database directly, and Mailpit on `8025` for reading the mail
the app sends.

### The queue worker

Emails are sent by the `worker` container, which runs `messenger:consume async`.

docker compose restart worker    # after changing PHP code, otherwise it runs the code it started with
docker compose logs -f worker    # watch messages being handled

A message that fails is retried three times and then moves to the `failed` queue
(`messenger:failed:show` lists it).

## Trying it

The quickest look is Swagger UI at **http://localhost:8080/api/doc**, where every endpoint
can be browsed and tried: run `POST /api/login`, press **Authorize** and paste the token.
Postman and Insomnia can also import the whole API from `/api/doc.json`.

The guided walkthrough below is plain `curl`, so it runs anywhere without importing anything.
The same requests work unchanged in Postman or Insomnia — set the body type to JSON and
put the token in an `Authorization: Bearer` header. The shell snippets assume bash; on
Windows use Git Bash rather than PowerShell, whose quoting differs.

Each step captures the id it just created.

**1. Log in as the admin you created during setup.**
The response is `{"token":"..."}`, unless invalid credentials were passed.

```bash
ADMIN=$(curl -s -X POST http://localhost:8080/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"YOUR_PASSWORD"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')
```

**2. Create a category and a product.** Note the price is a JSON *string* — sending
`19.99` as a number is rejected, and the reasoning is under "Decisions" below.

```bash
CATEGORY=$(curl -s -X POST http://localhost:8080/api/categories \
  -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d '{"name":"Tools"}' | sed -E 's/^\{"id":([0-9]+).*/\1/')

PRODUCT=$(curl -s -X POST http://localhost:8080/api/products \
  -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d "{\"name\":\"Hammer\",\"price\":\"19.99\",\"sku\":\"SKU-1\",\"categoryId\":$CATEGORY}" \
  | sed -E 's/^\{"id":([0-9]+).*/\1/')
```

**3. Set product stock.** Inventory has no `POST`; `PUT` creates the row when
a product has none and answers 201.

```bash
curl -s -X PUT "http://localhost:8080/api/inventory/$PRODUCT" \
  -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d '{"quantity":10,"lowStockThreshold":2}'
```

**4. Register a customer and place an order.**

```bash
curl -s -X POST http://localhost:8080/api/register \
  -H 'Content-Type: application/json' \
  -d '{"email":"customer@example.com","password":"pass1234"}'

CUSTOMER=$(curl -s -X POST http://localhost:8080/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"customer@example.com","password":"pass1234"}' \
  | sed -E 's/.*"token":"([^"]+)".*/\1/')

ORDER=$(curl -s -X POST http://localhost:8080/api/orders \
  -H "Authorization: Bearer $CUSTOMER" -H 'Content-Type: application/json' \
  -d "{\"items\":[{\"productId\":$PRODUCT,\"quantity\":3}]}" \
  | sed -E 's/^\{"id":([0-9]+).*/\1/')
```

The order comes back with `"totalPrice":"59.97"`, and
`GET /api/inventory/$PRODUCT` now reports 10 on hand, 3 reserved, 7 available.
A few seconds later the confirmation is in Mailpit at **http://localhost:8025**: the worker picked
the message up and mailed it.

**5. Move it through the lifecycle.** Status changes are admin-only, and the order
cannot skip confirmation — `shipped` straight from `pending` answers 409.

```bash
curl -s -X PUT "http://localhost:8080/api/orders/$ORDER/status" \
  -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d '{"status":"confirmed"}'

curl -s -X PUT "http://localhost:8080/api/orders/$ORDER/status" \
  -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d '{"status":"shipped"}'
```

Stock is now 7 on hand, 0 reserved — shipping consumed the reservation rather than
double-counting it.

**6. Read the audit trail**, which records who made each change. The first row is the
customer who placed the order, the next two are the admin who moved it:

```bash
curl -s "http://localhost:8080/api/orders/$ORDER/history" \
  -H "Authorization: Bearer $CUSTOMER"
```

**Things worth trying to break**, since the interesting behaviour is on the failure
paths: order more than is available (409), cancel an order that already shipped (409),
send `"price": 19.99` as a number (422), ask for someone else's order with your own
token (403), or change a status as a customer (403). Every one of them answers the same
error shape.

On the success path, order enough of the product to leave 2 or fewer (the threshold from
step 3): the admin gets a low-stock alert in Mailpit. Order one more and nothing is sent,
because the alert fires only when the threshold is crossed.

## Tests and quality

```bash
docker compose exec php php bin/phpunit
```

There are **196 tests, 637 assertions**, separated into three groups.
Unit tests are in `tests/Entity`, `tests/Enum` and `tests/EventListener` - they need no container.
`tests/Command`, `tests/Messenger` and `tests/MessageHandler` boot the kernel: `tests/Messenger` reads the
real queue table, and `tests/MessageHandler` runs the real handlers with a null mailer, so mails are asserted
without being sent.
Tests in `tests/Api` drive the full HTTP stack against a real MySQL database.
Among them, `ApiDocTest` fails when the OpenAPI spec drifts from the code, e.g. an undocumented
route or a required field documented as optional.

Five things the suite does on purpose:

**Failure tests assert the absence of side effects.** A rejected request must return 422
and leave the table untouched, to be sure that no persistence bug occurred.

**Tests actually check database rows** rather than trusting the response body, which is
serialised from the entity the controller just mutated and therefore looks correct even
when nothing was flushed. Dropping a `flush()` call was caught by two tests that would
otherwise both have passed.

**The concurrency test uses a second connection.** `OrderConcurrencyTest` opens a raw
DBAL connection, takes `SELECT ... FOR UPDATE` on the stock row, then places an order
over HTTP and asserts it *blocked* — measured by elapsed time against a lowered
`innodb_lock_wait_timeout`. A control case proves the same request is fast when nobody
holds the lock, because otherwise a blocked-looking result proves nothing.

**Every assertion was checked by breaking what it guards.** By hand, not with a tool:
change the production code a test is supposed to protect, run the suite, and confirm the
named test fails and nothing else does. This has repeatedly caught tests that were
green for the wrong reason, e.g. one asserted a query count while Doctrine served everything
from its identity map, or nothing checked which user was recorded
against an admin-driven status change of a customer-created order.

**Static analysis has no baseline.** PHPStan runs at level 8 over `src`, `tests` and
`migrations`, and every error it found was fixed in code rather than ignored or recorded
in a baseline file. CI runs it on every push, right after the code style check:

```bash
docker compose exec php vendor/bin/phpstan analyse --memory-limit=1G
```

## Decisions

**Money never touches a float.** Prices are `DECIMAL(10,2)` in MySQL, string in PHP and JSON.
Order totals are calculated using bcmath. This is all because of the imprecise nature of
floating-point arithmetic.

**DTOs in, entities out.** Requests bind to purpose-built DTO classes carrying validation
constraints; responses are entities filtered through serializer groups. Nothing crosses
over, which is what stops a client setting `id` or `role` by putting them in a payload.

**One error shape for the whole API.** Every failure under `/api` returns
`{"errors": {"field": ["message"]}}`. That holds for validation failures, 404 and 405,
for authentication failures, and for unexpected 500s.
Errors that belong to no field, like a 404 or a bad token, sit under the key `general`,
and no request field can ever be called `general`.
It takes two listeners rather than one, because Symfony and the JWT bundle fail through
different mechanisms.

**409 and 422 mean different things.** A request returns 422 when the values sent are
malformed and can never succeed as they are.
If a request can't succeed because of the current state, e.g. a duplicate SKU, but could succeed
at another time with the same values, it returns 409 instead.
The distinguishing question: would the identical request succeed later, unchanged?

**Stock is locked, not checked.** Placing an order takes `SELECT ... FOR UPDATE` on each
stock row inside a transaction. Rows are locked one statement at a time in sorted product
order, because a single `WHERE product_id IN (...)` would leave the locking order to the
query planner, and two orders touching the same two products could deadlock.
The locking query also sets `HINT_REFRESH`: `Product::$inventory` is the inverse side of
a one-to-one relation, so Doctrine has already populated the identity map from an *unlocked*
read, and without `HINT_REFRESH` a transaction that waited for the lock would be handed
the stale cached entity — silently defeating the thing it just waited for.

**The queue is a table, so a message can't outlive its order.** Messenger runs on the Doctrine
transport, on the same connection as ORM. `OrderPlacer` already works inside a transaction,
so dispatching `OrderPlaced` there is an `INSERT` in that transaction: the order and its message
commit or roll back together, an outbox with no relay process. The worker reads with
`FOR UPDATE SKIP LOCKED`, which skips rows from a transaction that has not committed, so it never
loads an order that doesn't exist yet. The DSN says `auto_setup=0` because the migration owns the
table.

The price of the table is polling: an idle worker runs one short locking `SELECT` a second, and each
message costs an insert, an update and a delete. That is negligible at this size and the first thing to
measure if volume grows by orders of magnitude. Moving to another broker later means keeping the
table on the dispatching side and adding that relay.

**Mail is sent inside the handler, and the stock alert fires only on crossing.** By default the mailer
does not send mail itself: it puts the mail on the Messenger bus as a second message. The handler
would then finish and record the confirmation as sent while the mail was still waiting in the queue,
and if delivery failed, only that second message would be retried, while the handler had already finished
and marked the order as confirmed. `framework.mailer.message_bus` is `false`, so the handler talks to
the mail server directly. A failed send fails the handler, and the whole message is retried.
The low-stock alert compares `isLowStock()` before and after a change and fires only when it flips;
otherwise every order placed while stock is low would mail the admins again. The handler checks once more 
when it runs, because the mail reports current numbers and the stock may have been replenished in between.

**Logs go where the environment collects them.** Dev and test write to files under
`var/log`, so a test run shows its own output and nothing else. Production writes JSON
to `stderr` instead, because in a container the runtime is what collects logs.

**Item prices are copied onto the order row.** Repricing a product must not retroactively
change what somebody already paid.

**Credentials are committed in `.env`.** These are placeholders addressing containers on
your own machine, and committing them means `docker compose up` works immediately.
This is also Symfony's own convention for that file. Nothing in it is a real secret: the JWT
*private key* is generated locally and gitignored, and the passphrase in `.env` is a
visible placeholder protecting a key that only ever exists on your machine. A real
deployment supplies all of it through the environment.

**An N+1 that no serializer group could fix.** `Product::$inventory` is the *inverse* side
of a one-to-one relation, which Doctrine cannot defer: to decide whether the property is
null it has to query, and it does so during hydration, before any serializer group is
consulted. Excluding the field from the output changed nothing. The fix is an explicit
join in `ProductRepository::paginate()`, measured on a three-product page:
**5 queries without the join, 2 with it.** The two are the floor for any paginated
listing — one count, one fetch; the extra three were one inventory lookup per product,
growing with the page size.

## Known problems

Stated plainly rather than left to be discovered.

**Running it on Windows is slow, and it is not the application's fault.** Docker Desktop crosses
a host-to-VM filesystem boundary — measured at roughly 0.9 ms per `stat()` against 0.0001 ms on
container-local disk — and PHP touches thousands of files per request. It does not apply in production,
which runs on Linux with no VM boundary, and the `prod` Dockerfile stage copies the source into the
image rather than bind-mounting it.

**The inventory update endpoint has a read-compare-write race.** It rejects a quantity
below what is currently reserved, but reads and writes without a row lock, so a concurrent
reservation can encounter this problem. `ship()` refuses in that case instead of driving
stock negative, so the damage is bounded and a cancellation restores consistency, but the
drift itself is still reachable. Order placement, where it actually matters, does hold the lock.

## Further development

- **Infection**, to automate the by-hand mutation checks described under Tests
- **Seed data**, so a fresh setup has categories, products and stock to try the API against without creating them by hand first
- **Domain indexes and a timestamp-precision migration** as schema changes
- **Promo codes applied to order totals**, which is what the `bcmath` groundwork is for
- **Row locking on the inventory endpoint**, closing the race described in the Known problems section above
