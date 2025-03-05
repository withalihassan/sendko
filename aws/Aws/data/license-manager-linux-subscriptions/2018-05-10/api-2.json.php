<?php
// This file was auto-generated from sdk-root/src/data/license-manager-linux-subscriptions/2018-05-10/api-2.json
return [ 'version' => '2.0', 'metadata' => [ 'apiVersion' => '2018-05-10', 'endpointPrefix' => 'license-manager-linux-subscriptions', 'jsonVersion' => '1.1', 'protocol' => 'rest-json', 'protocols' => [ 'rest-json', ], 'serviceFullName' => 'AWS License Manager Linux Subscriptions', 'serviceId' => 'License Manager Linux Subscriptions', 'signatureVersion' => 'v4', 'signingName' => 'license-manager-linux-subscriptions', 'uid' => 'license-manager-linux-subscriptions-2018-05-10', 'auth' => [ 'aws.auth#sigv4', ], ], 'operations' => [ 'DeregisterSubscriptionProvider' => [ 'name' => 'DeregisterSubscriptionProvider', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/DeregisterSubscriptionProvider', 'responseCode' => 200, ], 'input' => [ 'shape' => 'DeregisterSubscriptionProviderRequest', ], 'output' => [ 'shape' => 'DeregisterSubscriptionProviderResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ResourceNotFoundException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'GetRegisteredSubscriptionProvider' => [ 'name' => 'GetRegisteredSubscriptionProvider', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/GetRegisteredSubscriptionProvider', 'responseCode' => 200, ], 'input' => [ 'shape' => 'GetRegisteredSubscriptionProviderRequest', ], 'output' => [ 'shape' => 'GetRegisteredSubscriptionProviderResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ResourceNotFoundException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'GetServiceSettings' => [ 'name' => 'GetServiceSettings', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/GetServiceSettings', 'responseCode' => 200, ], 'input' => [ 'shape' => 'GetServiceSettingsRequest', ], 'output' => [ 'shape' => 'GetServiceSettingsResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'ListLinuxSubscriptionInstances' => [ 'name' => 'ListLinuxSubscriptionInstances', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/ListLinuxSubscriptionInstances', 'responseCode' => 200, ], 'input' => [ 'shape' => 'ListLinuxSubscriptionInstancesRequest', ], 'output' => [ 'shape' => 'ListLinuxSubscriptionInstancesResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'ListLinuxSubscriptions' => [ 'name' => 'ListLinuxSubscriptions', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/ListLinuxSubscriptions', 'responseCode' => 200, ], 'input' => [ 'shape' => 'ListLinuxSubscriptionsRequest', ], 'output' => [ 'shape' => 'ListLinuxSubscriptionsResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'ListRegisteredSubscriptionProviders' => [ 'name' => 'ListRegisteredSubscriptionProviders', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/ListRegisteredSubscriptionProviders', 'responseCode' => 200, ], 'input' => [ 'shape' => 'ListRegisteredSubscriptionProvidersRequest', ], 'output' => [ 'shape' => 'ListRegisteredSubscriptionProvidersResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'ListTagsForResource' => [ 'name' => 'ListTagsForResource', 'http' => [ 'method' => 'GET', 'requestUri' => '/tags/{resourceArn}', 'responseCode' => 200, ], 'input' => [ 'shape' => 'ListTagsForResourceRequest', ], 'output' => [ 'shape' => 'ListTagsForResourceResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ResourceNotFoundException', ], [ 'shape' => 'ValidationException', ], ], ], 'RegisterSubscriptionProvider' => [ 'name' => 'RegisterSubscriptionProvider', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/RegisterSubscriptionProvider', 'responseCode' => 200, ], 'input' => [ 'shape' => 'RegisterSubscriptionProviderRequest', ], 'output' => [ 'shape' => 'RegisterSubscriptionProviderResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'TagResource' => [ 'name' => 'TagResource', 'http' => [ 'method' => 'PUT', 'requestUri' => '/tags/{resourceArn}', 'responseCode' => 200, ], 'input' => [ 'shape' => 'TagResourceRequest', ], 'output' => [ 'shape' => 'TagResourceResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ResourceNotFoundException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], 'UntagResource' => [ 'name' => 'UntagResource', 'http' => [ 'method' => 'DELETE', 'requestUri' => '/tags/{resourceArn}', 'responseCode' => 200, ], 'input' => [ 'shape' => 'UntagResourceRequest', ], 'output' => [ 'shape' => 'UntagResourceResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ResourceNotFoundException', ], ], 'idempotent' => true, ], 'UpdateServiceSettings' => [ 'name' => 'UpdateServiceSettings', 'http' => [ 'method' => 'POST', 'requestUri' => '/subscription/UpdateServiceSettings', 'responseCode' => 200, ], 'input' => [ 'shape' => 'UpdateServiceSettingsRequest', ], 'output' => [ 'shape' => 'UpdateServiceSettingsResponse', ], 'errors' => [ [ 'shape' => 'InternalServerException', ], [ 'shape' => 'ThrottlingException', ], [ 'shape' => 'ValidationException', ], ], 'idempotent' => true, ], ], 'shapes' => [ 'Boolean' => [ 'type' => 'boolean', 'box' => true, ], 'BoxInteger' => [ 'type' => 'integer', 'box' => true, ], 'BoxLong' => [ 'type' => 'long', 'box' => true, ], 'DeregisterSubscriptionProviderRequest' => [ 'type' => 'structure', 'required' => [ 'SubscriptionProviderArn', ], 'members' => [ 'SubscriptionProviderArn' => [ 'shape' => 'SubscriptionProviderArn', ], ], ], 'DeregisterSubscriptionProviderResponse' => [ 'type' => 'structure', 'members' => [], ], 'Filter' => [ 'type' => 'structure', 'members' => [ 'Name' => [ 'shape' => 'String', ], 'Operator' => [ 'shape' => 'Operator', ], 'Values' => [ 'shape' => 'StringList', ], ], ], 'FilterList' => [ 'type' => 'list', 'member' => [ 'shape' => 'Filter', ], ], 'GetRegisteredSubscriptionProviderRequest' => [ 'type' => 'structure', 'required' => [ 'SubscriptionProviderArn', ], 'members' => [ 'SubscriptionProviderArn' => [ 'shape' => 'SubscriptionProviderArn', ], ], ], 'GetRegisteredSubscriptionProviderResponse' => [ 'type' => 'structure', 'members' => [ 'LastSuccessfulDataRetrievalTime' => [ 'shape' => 'String', ], 'SecretArn' => [ 'shape' => 'SecretArn', ], 'SubscriptionProviderArn' => [ 'shape' => 'SubscriptionProviderArn', ], 'SubscriptionProviderSource' => [ 'shape' => 'SubscriptionProviderSource', ], 'SubscriptionProviderStatus' => [ 'shape' => 'SubscriptionProviderStatus', ], 'SubscriptionProviderStatusMessage' => [ 'shape' => 'String', ], ], ], 'GetServiceSettingsRequest' => [ 'type' => 'structure', 'members' => [], ], 'GetServiceSettingsResponse' => [ 'type' => 'structure', 'members' => [ 'HomeRegions' => [ 'shape' => 'StringList', ], 'LinuxSubscriptionsDiscovery' => [ 'shape' => 'LinuxSubscriptionsDiscovery', ], 'LinuxSubscriptionsDiscoverySettings' => [ 'shape' => 'LinuxSubscriptionsDiscoverySettings', ], 'Status' => [ 'shape' => 'Status', ], 'StatusMessage' => [ 'shape' => 'StringMap', ], ], ], 'Instance' => [ 'type' => 'structure', 'members' => [ 'AccountID' => [ 'shape' => 'String', ], 'AmiId' => [ 'shape' => 'String', ], 'DualSubscription' => [ 'shape' => 'String', ], 'InstanceID' => [ 'shape' => 'String', ], 'InstanceType' => [ 'shape' => 'String', ], 'LastUpdatedTime' => [ 'shape' => 'String', ], 'OsVersion' => [ 'shape' => 'String', ], 'ProductCode' => [ 'shape' => 'ProductCodeList', ], 'Region' => [ 'shape' => 'String', ], 'RegisteredWithSubscriptionProvider' => [ 'shape' => 'String', ], 'Status' => [ 'shape' => 'String', ], 'SubscriptionName' => [ 'shape' => 'String', ], 'SubscriptionProviderCreateTime' => [ 'shape' => 'String', ], 'SubscriptionProviderUpdateTime' => [ 'shape' => 'String', ], 'UsageOperation' => [ 'shape' => 'String', ], ], ], 'InstanceList' => [ 'type' => 'list', 'member' => [ 'shape' => 'Instance', ], ], 'InternalServerException' => [ 'type' => 'structure', 'members' => [ 'message' => [ 'shape' => 'String', ], ], 'exception' => true, 'fault' => true, ], 'LinuxSubscriptionsDiscovery' => [ 'type' => 'string', 'enum' => [ 'Enabled', 'Disabled', ], ], 'LinuxSubscriptionsDiscoverySettings' => [ 'type' => 'structure', 'required' => [ 'OrganizationIntegration', 'SourceRegions', ], 'members' => [ 'OrganizationIntegration' => [ 'shape' => 'OrganizationIntegration', ], 'SourceRegions' => [ 'shape' => 'StringList', ], ], ], 'ListLinuxSubscriptionInstancesRequest' => [ 'type' => 'structure', 'members' => [ 'Filters' => [ 'shape' => 'FilterList', ], 'MaxResults' => [ 'shape' => 'BoxInteger', ], 'NextToken' => [ 'shape' => 'ListLinuxSubscriptionInstancesRequestNextTokenString', ], ], ], 'ListLinuxSubscriptionInstancesRequestNextTokenString' => [ 'type' => 'string', 'max' => 16384, 'min' => 1, ], 'ListLinuxSubscriptionInstancesResponse' => [ 'type' => 'structure', 'members' => [ 'Instances' => [ 'shape' => 'InstanceList', ], 'NextToken' => [ 'shape' => 'String', ], ], ], 'ListLinuxSubscriptionsRequest' => [ 'type' => 'structure', 'members' => [ 'Filters' => [ 'shape' => 'FilterList', ], 'MaxResults' => [ 'shape' => 'BoxInteger', ], 'NextToken' => [ 'shape' => 'ListLinuxSubscriptionsRequestNextTokenString', ], ], ], 'ListLinuxSubscriptionsRequestNextTokenString' => [ 'type' => 'string', 'max' => 16384, 'min' => 1, ], 'ListLinuxSubscriptionsResponse' => [ 'type' => 'structure', 'members' => [ 'NextToken' => [ 'shape' => 'String', ], 'Subscriptions' => [ 'shape' => 'SubscriptionList', ], ], ], 'ListRegisteredSubscriptionProvidersRequest' => [ 'type' => 'structure', 'members' => [ 'MaxResults' => [ 'shape' => 'ListRegisteredSubscriptionProvidersRequestMaxResultsInteger', ], 'NextToken' => [ 'shape' => 'String', ], 'SubscriptionProviderSources' => [ 'shape' => 'SubscriptionProviderSourceList', ], ], ], 'ListRegisteredSubscriptionProvidersRequestMaxResultsInteger' => [ 'type' => 'integer', 'box' => true, 'max' => 100, 'min' => 1, ], 'ListRegisteredSubscriptionProvidersResponse' => [ 'type' => 'structure', 'members' => [ 'NextToken' => [ 'shape' => 'String', ], 'RegisteredSubscriptionProviders' => [ 'shape' => 'RegisteredSubscriptionProviderList', ], ], ], 'ListTagsForResourceRequest' => [ 'type' => 'structure', 'required' => [ 'resourceArn', ], 'members' => [ 'resourceArn' => [ 'shape' => 'SubscriptionProviderArn', 'location' => 'uri', 'locationName' => 'resourceArn', ], ], ], 'ListTagsForResourceResponse' => [ 'type' => 'structure', 'members' => [ 'tags' => [ 'shape' => 'Tags', ], ], ], 'Operator' => [ 'type' => 'string', 'enum' => [ 'Equal', 'NotEqual', 'Contains', ], 'max' => 20, 'min' => 1, ], 'OrganizationIntegration' => [ 'type' => 'string', 'enum' => [ 'Enabled', 'Disabled', ], ], 'ProductCodeList' => [ 'type' => 'list', 'member' => [ 'shape' => 'String', ], ], 'RegisterSubscriptionProviderRequest' => [ 'type' => 'structure', 'required' => [ 'SecretArn', 'SubscriptionProviderSource', ], 'members' => [ 'SecretArn' => [ 'shape' => 'SecretArn', ], 'SubscriptionProviderSource' => [ 'shape' => 'SubscriptionProviderSource', ], 'Tags' => [ 'shape' => 'Tags', ], ], ], 'RegisterSubscriptionProviderResponse' => [ 'type' => 'structure', 'members' => [ 'SubscriptionProviderArn' => [ 'shape' => 'String', ], 'SubscriptionProviderSource' => [ 'shape' => 'SubscriptionProviderSource', ], 'SubscriptionProviderStatus' => [ 'shape' => 'SubscriptionProviderStatus', ], ], ], 'RegisteredSubscriptionProvider' => [ 'type' => 'structure', 'members' => [ 'LastSuccessfulDataRetrievalTime' => [ 'shape' => 'String', ], 'SecretArn' => [ 'shape' => 'SecretArn', ], 'SubscriptionProviderArn' => [ 'shape' => 'SubscriptionProviderArn', ], 'SubscriptionProviderSource' => [ 'shape' => 'SubscriptionProviderSource', ], 'SubscriptionProviderStatus' => [ 'shape' => 'SubscriptionProviderStatus', ], 'SubscriptionProviderStatusMessage' => [ 'shape' => 'String', ], ], ], 'RegisteredSubscriptionProviderList' => [ 'type' => 'list', 'member' => [ 'shape' => 'RegisteredSubscriptionProvider', ], ], 'ResourceNotFoundException' => [ 'type' => 'structure', 'members' => [ 'message' => [ 'shape' => 'String', ], ], 'exception' => true, ], 'SecretArn' => [ 'type' => 'string', 'pattern' => '^arn:[a-z0-9-\\.]{1,63}:secretsmanager:[a-z0-9-\\.]{0,63}:[a-z0-9-\\.]{0,63}:secret:[^/]{1,1023}$', ], 'Status' => [ 'type' => 'string', 'enum' => [ 'InProgress', 'Completed', 'Successful', 'Failed', ], ], 'String' => [ 'type' => 'string', ], 'StringList' => [ 'type' => 'list', 'member' => [ 'shape' => 'StringListMemberString', ], 'max' => 100, 'min' => 1, ], 'StringListMemberString' => [ 'type' => 'string', 'max' => 100, 'min' => 1, ], 'StringMap' => [ 'type' => 'map', 'key' => [ 'shape' => 'String', ], 'value' => [ 'shape' => 'String', ], ], 'Subscription' => [ 'type' => 'structure', 'members' => [ 'InstanceCount' => [ 'shape' => 'BoxLong', ], 'Name' => [ 'shape' => 'String', ], 'Type' => [ 'shape' => 'String', ], ], ], 'SubscriptionList' => [ 'type' => 'list', 'member' => [ 'shape' => 'Subscription', ], ], 'SubscriptionProviderArn' => [ 'type' => 'string', 'pattern' => '^arn:[a-z0-9-\\.]{1,63}:[a-z0-9-\\.]{1,63}:[a-z0-9-\\.]{1,63}:[a-z0-9-\\.]{1,63}:[a-z0-9-\\.]{1,510}/[a-z0-9-\\.]{1,510}$', ], 'SubscriptionProviderSource' => [ 'type' => 'string', 'enum' => [ 'RedHat', ], ], 'SubscriptionProviderSourceList' => [ 'type' => 'list', 'member' => [ 'shape' => 'SubscriptionProviderSource', ], ], 'SubscriptionProviderStatus' => [ 'type' => 'string', 'enum' => [ 'ACTIVE', 'INVALID', 'PENDING', ], ], 'TagKeyList' => [ 'type' => 'list', 'member' => [ 'shape' => 'String', ], 'max' => 50, 'min' => 0, 'sensitive' => true, ], 'TagResourceRequest' => [ 'type' => 'structure', 'required' => [ 'resourceArn', 'tags', ], 'members' => [ 'resourceArn' => [ 'shape' => 'SubscriptionProviderArn', 'location' => 'uri', 'locationName' => 'resourceArn', ], 'tags' => [ 'shape' => 'Tags', ], ], ], 'TagResourceResponse' => [ 'type' => 'structure', 'members' => [], ], 'Tags' => [ 'type' => 'map', 'key' => [ 'shape' => 'String', ], 'value' => [ 'shape' => 'String', ], 'max' => 50, 'min' => 0, 'sensitive' => true, ], 'ThrottlingException' => [ 'type' => 'structure', 'members' => [ 'message' => [ 'shape' => 'String', ], ], 'exception' => true, ], 'UntagResourceRequest' => [ 'type' => 'structure', 'required' => [ 'resourceArn', 'tagKeys', ], 'members' => [ 'resourceArn' => [ 'shape' => 'SubscriptionProviderArn', 'location' => 'uri', 'locationName' => 'resourceArn', ], 'tagKeys' => [ 'shape' => 'TagKeyList', 'location' => 'querystring', 'locationName' => 'tagKeys', ], ], ], 'UntagResourceResponse' => [ 'type' => 'structure', 'members' => [], ], 'UpdateServiceSettingsRequest' => [ 'type' => 'structure', 'required' => [ 'LinuxSubscriptionsDiscovery', 'LinuxSubscriptionsDiscoverySettings', ], 'members' => [ 'AllowUpdate' => [ 'shape' => 'Boolean', ], 'LinuxSubscriptionsDiscovery' => [ 'shape' => 'LinuxSubscriptionsDiscovery', ], 'LinuxSubscriptionsDiscoverySettings' => [ 'shape' => 'LinuxSubscriptionsDiscoverySettings', ], ], ], 'UpdateServiceSettingsResponse' => [ 'type' => 'structure', 'members' => [ 'HomeRegions' => [ 'shape' => 'StringList', ], 'LinuxSubscriptionsDiscovery' => [ 'shape' => 'LinuxSubscriptionsDiscovery', ], 'LinuxSubscriptionsDiscoverySettings' => [ 'shape' => 'LinuxSubscriptionsDiscoverySettings', ], 'Status' => [ 'shape' => 'Status', ], 'StatusMessage' => [ 'shape' => 'StringMap', ], ], ], 'ValidationException' => [ 'type' => 'structure', 'members' => [ 'message' => [ 'shape' => 'String', ], ], 'exception' => true, ], ],];
