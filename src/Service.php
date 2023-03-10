<?php

declare(strict_types=1);

namespace Neucore\Plugin\Mumble;

use Neucore\Plugin\Core\FactoryInterface;
use Neucore\Plugin\Data\CoreAccount;
use Neucore\Plugin\Data\CoreCharacter;
use Neucore\Plugin\Data\CoreGroup;
use Neucore\Plugin\Data\PluginConfiguration;
use Neucore\Plugin\Data\ServiceAccountData;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;

/** @noinspection PhpUnused */
class Service implements ServiceInterface
{
    private const PLACEHOLDER_CHAR_NAME = '{characterName}';

    private const MUMBLE_SUPERUSER = 'SuperUser';

    private LoggerInterface $logger;

    private FactoryInterface $factory;

    private PluginConfiguration $pluginConfiguration;

    private ?ConfigurationData $configurationData = null;

    private ?PDO $pdo = null;

    public function __construct(
        LoggerInterface $logger,
        PluginConfiguration $pluginConfiguration,
        FactoryInterface $factory,
    ) {
        $this->logger = $logger;
        $this->pluginConfiguration = $pluginConfiguration;
        $this->factory = $factory;
    }

    public function onConfigurationChange(): void
    {
    }

    /**
     * @throws Exception
     */
    public function request(
        string $name,
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?CoreAccount $coreAccount,
    ): ResponseInterface {
        throw new Exception();
    }

    /**
     * @param CoreCharacter[] $characters
     * @return ServiceAccountData[]
     * @throws Exception
     */
    public function getAccounts(array $characters): array
    {
        if (count($characters) === 0) {
            return [];
        }

        $this->dbConnect();

        $characterIdsOwnerHashes = [];
        foreach ($characters as $character) {
            $characterIdsOwnerHashes[$character->id] = $character->ownerHash;
        }

        $placeholders = implode(',', array_fill(0, count($characterIdsOwnerHashes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT character_id, mumble_username, mumble_password, mumble_fullname, owner_hash, account_active
            FROM user
            WHERE character_id IN ($placeholders)"
        );
        try {
            $stmt->execute(array_keys($characterIdsOwnerHashes));
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $characterId = (int)$row['character_id'];
            $password = $row['mumble_password'];
            if (
                !empty($characterIdsOwnerHashes[$characterId]) &&
                $row['owner_hash'] !== $characterIdsOwnerHashes[$characterId]
            ) {
                $password = $this->updateOwner($characterId, $characterIdsOwnerHashes[$characterId]);
            }
            $result[] = new ServiceAccountData(
                $characterId,
                $row['mumble_username'],
                $password,
                null,
                $row['account_active'] ? ServiceAccountData::STATUS_ACTIVE : ServiceAccountData::STATUS_DEACTIVATED,
                $row['mumble_fullname'],
            );
        }

        return $result;
    }

    /**
     * @param CoreGroup[] $groups
     * @param int[] $allCharacterIds
     * @throws Exception
     */
    public function register(
        CoreCharacter $character,
        array $groups,
        string $emailAddress,
        array $allCharacterIds
    ): ServiceAccountData {
        if (empty($character->name)) {
            throw new Exception();
        }

        $this->dbConnect();

        // add ticker
        $this->addTicker($character);

        // add user
        $mumbleUsername = $this->toMumbleName($character->name);
        $mumblePassword = $this->randomString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO user (character_id, character_name, corporation_id, corporation_name, 
                  alliance_id, alliance_name, mumble_username, mumble_password, `groups`, created_at, 
                  updated_at, owner_hash, mumble_fullname, account_active) 
              VALUES (:character_id, :character_name, :corporation_id, :corporation_name, 
                      :alliance_id, :alliance_name, :mumble_username, :mumble_password, :groups, :created_at, 
                      :updated_at, :owner_hash, :mumble_fullname, :account_active)'
        );
        $created = time();
        $groupNames = $this->groupNames($groups);
        $fullName = $this->generateMumbleFullName($character, $groupNames);
        $stmt->bindValue(':character_id', $character->id);
        $stmt->bindValue(':character_name', $character->name);
        $stmt->bindValue(':corporation_id', (int)$character->corporationId);
        $stmt->bindValue(':corporation_name', (string)$character->corporationName);
        $stmt->bindValue(':alliance_id', $character->allianceId);
        $stmt->bindValue(':alliance_name', $character->allianceName);
        $stmt->bindValue(':mumble_username', $mumbleUsername);
        $stmt->bindValue(':mumble_password', $mumblePassword);
        $stmt->bindValue(':groups', $groupNames);
        $stmt->bindValue(':created_at', $created);
        $stmt->bindValue(':updated_at', $created);
        $stmt->bindValue(':owner_hash', (string)$character->ownerHash);
        $stmt->bindValue(':mumble_fullname', $fullName);
        $stmt->bindValue(':account_active', 1);
        try {
            $stmt->execute();
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return new ServiceAccountData(
            $character->id,
            $mumbleUsername,
            $mumblePassword,
            null,
            ServiceAccountData::STATUS_ACTIVE,
        );
    }

    public function updateAccount(CoreCharacter $character, array $groups, ?CoreCharacter $mainCharacter): void
    {
        $this->dbConnect();

        // Remove account if character does not exist on Neucore.
        if ($character->playerId === 0) {
            $this->deleteAccount($character->id);
            return;
        }

        $this->addTicker($character);

        $this->updateUser($character, $groups);

        $this->updateBan($character, $groups);
    }

    public function updatePlayerAccount(CoreCharacter $mainCharacter, array $groups): void
    {
        throw new Exception();
    }

    public function moveServiceAccount(int $toPlayerId, int $fromPlayerId): bool
    {
        return true;
    }

    public function resetPassword(int $characterId): string
    {
        $this->dbConnect();

        $newPassword = $this->randomString();

        $stmt = $this->pdo->prepare(
            'UPDATE user SET mumble_password = :mumble_password WHERE character_id = :character_id'
        );
        try {
            $stmt->execute([':character_id' => $characterId, ':mumble_password' => $newPassword]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return $newPassword;
    }

    public function getAllAccounts(): array
    {
        $this->dbConnect();

        $stmt = $this->pdo->prepare("SELECT character_id FROM user ORDER BY updated_at");
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return array_map(function (array $row) {
            return (int)$row['character_id'];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllPlayerAccounts(): array
    {
        return [];
    }

    public function search(string $query): array
    {
        return [];
    }

    private function updateOwner(int $characterId, string $newOwnerHash): ?string
    {
        $password = $this->randomString();

        $stmt = $this->pdo->prepare(
            'UPDATE user SET owner_hash = :hash, mumble_password = :pw WHERE character_id = :id'
        );
        try {
            $stmt->execute([':id' => $characterId, ':pw' => $password, ':hash' => $newOwnerHash]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            return null;
        }

        return $password;
    }

    private function addTicker(CoreCharacter $character): void
    {
        foreach ([
             'corporation' => [$character->corporationId, $character->corporationTicker],
             'alliance' => [$character->allianceId, $character->allianceTicker]
         ] as $type => $ticker) {
            if (empty($ticker[0]) || empty($ticker[1])) {
                continue;
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO ticker (filter, text) 
                VALUES (:filter, :text) 
                ON DUPLICATE KEY UPDATE text = :text'
            );
            $stmt->bindValue(':filter', $type . '-' . $ticker[0]);
            $stmt->bindValue(':text', $ticker[1]);
            try {
                $stmt->execute();
            } catch(PDOException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }
        }
    }

    /**
     * @param CoreGroup[] $groups
     */
    private function groupNames(array $groups): string
    {
        return implode(',', array_map(function (CoreGroup $group) {
            return $group->name;
        }, $groups));
    }

    /**
     * @param CoreGroup[] $groups
     * @return int[]
     */
    private function groupIds(array $groups): array
    {
        return array_map(function (CoreGroup $group) {
            return $group->identifier;
        }, $groups);
    }

    /**
     * @throws Exception
     */
    private function toMumbleName(string $characterName): string
    {
        $name = strtolower(preg_replace("/[^A-Za-z0-9\-]/", '_', $characterName));
        $nameToCheck = $name;

        $unique = false;
        $count = 0;
        while (!$unique && $count < 900) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM user WHERE mumble_username = ?');
            try {
                $stmt->execute([$nameToCheck]);
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
                throw new Exception();
            }
            if ($stmt->rowCount() === 0 && $nameToCheck !== strtolower(self::MUMBLE_SUPERUSER)) {
                $unique = true;
            } else {
                $count ++;
                $nameToCheck = "{$name}_$count";
            }
        }

        return $nameToCheck;
    }

    /**
     * @throws Exception
     */
    private function generateMumbleFullName(CoreCharacter $character, string $groups): string
    {
        $config = $this->readConfig();

        $tagsPlaceholder = '{tags}';
        $corpPlaceholder = '{corporationTicker}';
        $alliPlaceholder = '{allianceTicker}';

        // read enclosure
        list($tagPrefix, $tagSuffix) = $this->findEnclosure($config->nickname, $tagsPlaceholder);
        list($corpPrefix, $corpSuffix) = $this->findEnclosure($config->nickname, $corpPlaceholder);
        list($alliPrefix, $alliSuffix) = $this->findEnclosure($config->nickname, $alliPlaceholder);

        // Find tags

        $assignedAdditionalTags = [];
        $allAdditionalTags = [];
        foreach ($config->additionalTagGroups as $additionalTagGroup) {
            $assignedAdditionalTags[] = null;
            foreach ($additionalTagGroup as $additionalTag) {
                $allAdditionalTags[] = $additionalTag;
            }
        }

        $groupsArray = explode(',', $groups);
        $mainTag = null;
        foreach ($config->groupsToTags as $group => $assignedTag) {
            if (!in_array($group, $groupsArray)) {
                continue;
            }
            if (in_array($assignedTag, $allAdditionalTags)) {
                // Assign additional tags
                foreach ($config->additionalTagGroups as $position => $additionalTagGroup) {
                    foreach ($additionalTagGroup as $additionalTag) {
                        if ($assignedTag === $additionalTag && $assignedAdditionalTags[$position] === null) {
                            $assignedAdditionalTags[$position] = "$tagPrefix$assignedTag$tagSuffix";
                        }
                    }
                }
            } elseif ($mainTag === null) {
                // Assign main tag
                $mainTag = (string)$assignedTag;
            }
        }

        $finalAdditionalTags = array_filter($assignedAdditionalTags, function ($tag) { return $tag !== null; });

        // Build nickname
        $displayName = str_replace(
            [
                self::PLACEHOLDER_CHAR_NAME,
                "$tagPrefix$tagsPlaceholder$tagSuffix",
                "$corpPrefix$corpPlaceholder$corpSuffix",
                "$alliPrefix$alliPlaceholder$alliSuffix",
            ],
            [
                $character->name,
                implode(' ', $finalAdditionalTags) .
                    ($config->mainTagReplacesCorporationTicker || !$mainTag ? '' : " $tagPrefix$mainTag$tagSuffix"),
                $config->mainTagReplacesCorporationTicker && $mainTag ?
                    $tagPrefix . $mainTag . $tagSuffix :
                    $corpPrefix . $character->corporationTicker . $corpSuffix,
                $character->allianceTicker ? $alliPrefix . $character->allianceTicker . $alliSuffix : '',
            ],
            $config->nickname
        );

        return trim(preg_replace('/\s+/', ' ', $displayName));
    }

    private function findEnclosure(string $template, string $placeholder): array
    {
        $position = strpos($template, $placeholder);

        $prefix = '';
        if ($position > 0) {
            $prefix = trim(substr($template, $position - 1, 1));
        }

        $suffix = trim(substr($template, $position + strlen($placeholder), 1));

        return [$prefix, $suffix];
    }

    private function randomString(): string
    {
        $characters = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789";
        $max = mb_strlen($characters) - 1;
        $pass = '';
        for ($i = 0; $i < 10; $i++) {
            try {
                $pass .= $characters[random_int(0, $max)];
            } catch (\Exception) {
                $pass .= $characters[rand(0, $max)];
            }
        }
        return $pass;
    }

    /**
     * @throws Exception
     */
    private function deleteAccount(int $characterId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user WHERE character_id = ?');
        try {
            $stmt->execute([$characterId]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
    }

    /**
     * There are some accounts with an empty Mumble username, generate a new name for those, return the existing
     * name for others.
     *
     * @throws Exception
     */
    private function getMumbleUsername(CoreCharacter $character): string
    {
        $stmtSelect = $this->pdo->prepare("SELECT mumble_username FROM user WHERE character_id = :id");
        try {
            $stmtSelect->execute([':id' => $character->id]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
        $userNameResult = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

        if (
            isset($userNameResult[0]) &&
            !empty($userNameResult[0]['mumble_username']) &&
            strtolower($userNameResult[0]['mumble_username']) !== strtolower(self::MUMBLE_SUPERUSER)
        ) {
            $mumbleUsername = $userNameResult[0]['mumble_username'];
        } else {
            $mumbleUsername = $this->toMumbleName((string)$character->name);
            // the username may still be empty here
        }

        return $mumbleUsername;
    }

    /**
     * @param CoreGroup[] $groups
     * @throws Exception
     */
    private function updateUser(CoreCharacter $character, array $groups): void
    {
        $groupNames = $this->groupNames($groups);

        // Get Mumble username
        $mumbleUsername = $this->getMumbleUsername($character);
        $updateUserNameSqlPart = empty($mumbleUsername) ? '' : 'mumble_username = :mumble_username,';

        // Character name and Mumble full name - $character->name can be null!
        $mumbleFullName = $this->generateMumbleFullName($character, $groupNames);
        $updateFullNameSqlPart = empty($mumbleFullName) ? '' : 'mumble_fullname = :mumble_fullname,';
        $updateCharNameSqlPart = empty($character->name) ? '' : 'character_name = :character_name,';

        // Avatar
        $updateAvatarSqlPart = 'avatar = :avatar,';
        $avatar = '';
        if ($this->configurationData->showAvatar) {
            $avatar = $this->getAvatar($character->id);
            if (empty($avatar)) { // Don't update if HTTP request for image failed.
                $updateAvatarSqlPart = '';
            }
        }

        // Update user
        $stmt = $this->pdo->prepare(
            "UPDATE user
            SET `groups` = :groups, $updateCharNameSqlPart
                corporation_id = :corporation_id, corporation_name = :corporation_name,
                alliance_id = :alliance_id, alliance_name = :alliance_name,
                $updateUserNameSqlPart $updateFullNameSqlPart $updateAvatarSqlPart
                updated_at = :updated_at,
                account_active = :account_active
            WHERE character_id = :character_id"
        );
        $stmt->bindValue(':character_id', $character->id);
        if (!empty($character->name)) {
            $stmt->bindValue(':character_name', $character->name);
        }
        $stmt->bindValue(':corporation_id', (int)$character->corporationId);
        $stmt->bindValue(':corporation_name', (string)$character->corporationName);
        $stmt->bindValue(':alliance_id', $character->allianceId);
        $stmt->bindValue(':alliance_name', $character->allianceName);
        $stmt->bindValue(':groups', $groupNames);
        $stmt->bindValue(':updated_at', time());
        $stmt->bindValue(':account_active', $this->hasAnyRequiredGroup($groups));
        if (!empty($mumbleUsername)) {
            $stmt->bindValue(':mumble_username', $mumbleUsername);
        }
        if (!empty($mumbleFullName)) {
            $stmt->bindValue(':mumble_fullname', $mumbleFullName);
        }
        if (!empty($updateAvatarSqlPart)) {
            $stmt->bindValue(':avatar', $avatar);
        }
        try {
            $stmt->execute();
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
    }

    private function getAvatar(int $characterId): string
    {
        $url = "https://images.evetech.net/characters/$characterId/portrait?size=128&tenant=tranquility";
        $avatar = file_get_contents($url);
        return $avatar ?: '';
    }

    /**
     * Add/remove character from ban table
     *
     * @throws Exception
     */
    private function updateBan(CoreCharacter $character, array $groups): void
    {
        $banFilter = "character-$character->id";
        if (in_array($this->readConfig()->bannedGroup, $this->groupIds($groups))) {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO ban (filter, reason_public) VALUES (:filter, :reason)');
            $stmt->bindValue(':reason', 'banned');
        } else {
            $stmt = $this->pdo->prepare('DELETE FROM ban WHERE filter = :filter');
        }
        $stmt->bindValue(':filter', $banFilter);
        try {
            $stmt->execute();
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
    }

    /**
     * @param CoreGroup[] $groups
     * @return int 0 or 1
     */
    private function hasAnyRequiredGroup(array $groups): int
    {
        if (empty($this->pluginConfiguration->requiredGroups)) {
            return 1;
        }

        if (empty($groups)) {
            return 0;
        }

        $groupIds = array_map(function (CoreGroup $group) {
            return $group->identifier;
        }, $groups);

        if (empty(array_intersect($this->pluginConfiguration->requiredGroups, $groupIds))) {
            return 0;
        }

        return 1;
    }

    /**
     * @throws Exception
     */
    private function dbConnect(): void
    {
        if ($this->pdo === null) {
            $options = [];
            if (isset($_ENV['NEUCORE_MUMBLE_PLUGIN_DB_SSL_CA'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['NEUCORE_MUMBLE_PLUGIN_DB_SSL_CA'];
            }
            if (isset($_ENV['NEUCORE_MUMBLE_PLUGIN_DB_SSL_VERIFY'])) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $_ENV['NEUCORE_MUMBLE_PLUGIN_DB_SSL_VERIFY'] === '1';
            }
            try {
                $this->pdo = new PDO($_ENV[$this->readConfig()->databaseEnvVar] ?? '', null, null, $options);
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage() . ' at ' . __FILE__ . ':' . __LINE__);
                throw new Exception();
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    /**
     * @throws Exception
     */
    private function readConfig(): ConfigurationData
    {
        if ($this->configurationData) {
            return $this->configurationData;
        }

        try {
            $yaml = $this->factory->createSymfonyYamlParser()->parse($this->pluginConfiguration->configurationData);
        } catch (ParseException $e) {
            $this->logger->error($e->getMessage());
            throw new Exception('Failed to parse plugin configuration.');
        }

        if (
            empty($yaml['Nickname']) ||
            !str_contains($yaml['Nickname'], self::PLACEHOLDER_CHAR_NAME) ||
            !is_array($yaml['GroupsToTags'] ?? null) ||
            !isset($yaml['MainTagReplacesCorporationTicker'])
        ) {
            throw new Exception('Incomplete configuration.');
        }

        $this->configurationData = new ConfigurationData(
            $yaml['DatabaseEnvVar'] ?? 'NEUCORE_MUMBLE_PLUGIN_DB_DSN',
            $yaml['Nickname'],
            $yaml['GroupsToTags'],
            (bool)$yaml['MainTagReplacesCorporationTicker'],
            (bool)($yaml['ShowAvatar'] ?? false),
            $yaml['AdditionalTagGroups'] ?? [],
            isset($yaml['BannedGroup']) ? (int)$yaml['BannedGroup'] : null,
        );

        return $this->configurationData;
    }
}
