<?php

namespace ServerCommandBundle\Controller\Admin;

use ServerCommandBundle\Service\RemoteCommandService;
use ServerNodeBundle\Repository\NodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/terminal', name: 'admin_terminal_')]
class TerminalController extends AbstractController
{
    public function __construct(
        private readonly RemoteCommandService $remoteCommandService,
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    #[Route('/execute', name: 'execute', methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        $nodeId = $request->request->get('nodeId');
        $command = $request->request->get('command');
        $workingDir = $request->request->get('workingDir', '/root');
        $useSudo = filter_var($request->request->get('useSudo', 'false'), FILTER_VALIDATE_BOOLEAN);

        if (!$nodeId || !$command) {
            return new JsonResponse([
                'success' => false,
                'error' => '节点ID和命令不能为空',
            ], 400);
        }

        $node = $this->nodeRepository->find($nodeId);
        if (!$node) {
            return new JsonResponse([
                'success' => false,
                'error' => '节点不存在',
            ], 404);
        }

        try {
            // 创建并执行命令
            $remoteCommand = $this->remoteCommandService->createCommand(
                $node,
                '终端命令: ' . substr($command, 0, 50),
                $command,
                $workingDir,
                $useSudo, // 使用从请求获取的sudo参数
                30, // 30秒超时
                ['terminal']
            );

            $this->remoteCommandService->executeCommand($remoteCommand);

            return new JsonResponse([
                'success' => true,
                'result' => $remoteCommand->getResult() ?? '',
                'status' => $remoteCommand->getStatus()->value,
                'executionTime' => $remoteCommand->getExecutionTime(),
                'commandId' => $remoteCommand->getId(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/history/{nodeId}', name: 'history', methods: ['GET'])]
    public function history(int $nodeId): JsonResponse
    {
        $node = $this->nodeRepository->find($nodeId);
        if (!$node) {
            return new JsonResponse([
                'success' => false,
                'error' => '节点不存在',
            ], 404);
        }

        // 获取该节点最近的终端命令
        $commands = $this->remoteCommandService->getRepository()->findTerminalCommandsByNode($node, 20);

        $history = [];
        foreach ($commands as $command) {
            $history[] = [
                'id' => $command->getId(),
                'command' => $command->getCommand(),
                'result' => $command->getResult() ?? '',
                'status' => $command->getStatus()->value,
                'executedAt' => $command->getExecutedAt()?->format('Y-m-d H:i:s'),
                'executionTime' => $command->getExecutionTime(),
                'workingDirectory' => $command->getWorkingDirectory(),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'history' => $history,
        ]);
    }
}
