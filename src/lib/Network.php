<?php

/**
 * Network.php
 *
 * Origin of the current HTTP request: whether it comes from the local network (every
 * address of the request private or reserved: the peer, and the client given by a
 * reverse proxy). Used by the setup wizard (setup code required from Internet) and by
 * the purge of the data (refused from Internet).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

final class Network {

    /**
     * Whether every address given is local (private, loopback or reserved). No address,
     * or one that is not an IP, is not local.
     *
     * @param string $addresses The addresses, separated by commas (Setup::requestAddresses())
     * @return bool True if every address is local
     */
    public static function isLocal(string $addresses): bool
    {
        $list = array_filter(array_map('trim', explode(',', $addresses)), fn(string $address): bool => $address !== '');
        foreach ($list ?: [''] as $address) {
            $local = filter_var($address, FILTER_VALIDATE_IP) !== false
                && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            if (!$local) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the current request comes from the local network: its peer and every
     * client given by a reverse proxy (X-Forwarded-For, X-Real-IP, Forwarded).
     *
     * @return bool True if local
     */
    public static function isLocalRequest(): bool
    {
        return self::isLocal(Setup::requestAddresses());
    }
}
