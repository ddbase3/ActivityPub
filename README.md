# ActivityPub Plugin for BASE3

This plugin adds **ActivityPub** support to the BASE3 Framework, enabling websites and applications to participate in the **Fediverse**.

## Features

* WebFinger discovery (`/.well-known/webfinger`)
* ActivityPub Actor representation
* Inbox endpoint for receiving activities (e.g. Follow)
* Outbox endpoint for publishing activities (future)
* Signed HTTP requests (HTTP Signatures)
* Basic follower management

## Getting Started

1. Clone this repository into your BASE3 `plugin/` directory.
2. Enable the plugin via the BASE3 configuration.
3. Generate RSA keys for your ActivityPub actor:

   ```bash
   openssl genrsa -out private.pem 4096
   openssl rsa -in private.pem -pubout -out public.pem
   ```
4. Configure the actor name, domain, and key paths in the plugin settings.

## Usage

* After installation, your site can be followed from Fediverse clients (e.g. Mastodon) using `@actor@yourdomain.tld`.
* Incoming Follow requests are accepted and stored.
* Future versions will support posting, likes, and shares.

## Roadmap

* Full Outbox publishing (Create, Announce, Like)
* Followers collection endpoint
* Media attachments
* Integration with BASE3 agents and data services

## License

GPL 3 License

